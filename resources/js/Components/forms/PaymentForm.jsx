import React, { useEffect, useMemo, useRef, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import {
    AlertTriangle,
    ArrowLeft,
    Banknote,
    Check,
    ChevronDown,
    Receipt,
    Repeat,
    Search,
    Users,
    Wallet,
} from "lucide-react";

/**
 * Record a payment or an expense.
 *
 * WHAT WAS WRONG WITH THE OLD FORM
 * --------------------------------
 * The complaint was that the screen was hard to understand. It was, and the reasons were
 * concrete rather than cosmetic:
 *
 *  - THREE THINGS SET THE TRANSACTION TYPE and they disagreed. A "Type de transaction"
 *    select offered staff-payment vs expense; picking a person then silently overwrote the
 *    type based on their role; and handleSubmit() overwrote it a third time. Underneath sat
 *    a line of debug output — "Type de transaction actuel : payment" — shipped to the user.
 *    The type is not a choice: a teacher is paid from their wallet, an assistant is paid a
 *    salary. It is now shown as a consequence of who you picked, not asked as a question.
 *
 *  - THE "ALREADY PAID THIS MONTH" WARNING COULD NEVER FIRE. It read `transactions` from
 *    usePage().props, which is the Laravel PAGINATOR OBJECT, not an array — so its
 *    `Array.isArray()` guard returned false on every render and the function exited before
 *    doing anything. About 120 lines of warning logic that had never once run.
 *
 *  - NOTHING TOLD YOU HOW MUCH WAS AVAILABLE until after you had typed an amount, and if
 *    the amount was too high the server answered with `->with('error', ...)`, which the
 *    payments page did not render. You got redirected back to an empty form with no
 *    message. The balance is now on screen before you type, the remainder updates as you
 *    type, and the submit button refuses rather than letting you find out afterwards.
 *
 *  - THE "SOLDE RESTANT" FIELD ONLY APPEARED FOR SALARIES. Teachers are type `payment`,
 *    so the one group whose balance can actually run out never saw a remainder at all.
 *
 * WHY NATIVE <input type="date"> AND NOT react-datepicker
 * -------------------------------------------------------
 * The old form held dates as JS Date objects and posted
 * `date.toISOString().split("T")[0]`. toISOString converts to UTC first, so in Morocco
 * (UTC+1) any date picked at or after 23:00 local posts as the PREVIOUS day — which for a
 * payment on the 1st of a month lands it in the wrong month, and the month is what the
 * assistant salary cap is measured against. Dates are plain "YYYY-MM-DD" strings here,
 * start to finish, with no timezone in the path at all.
 */

const money = (value) =>
    `${new Intl.NumberFormat("fr-FR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0)} DH`;

const today = () => {
    // Local date, not UTC — see the note above.
    const d = new Date();
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const monthOf = (isoDate) => (isoDate || "").slice(0, 7);

const MONTHS_FR = [
    "janvier", "février", "mars", "avril", "mai", "juin",
    "juillet", "août", "septembre", "octobre", "novembre", "décembre",
];

const monthLabel = (isoDate) => {
    const [year, month] = (isoDate || "").split("-");
    return month ? `${MONTHS_FR[Number(month) - 1]} ${year}` : "";
};

const ROLE_LABEL = { teacher: "Enseignant", assistant: "Assistant" };

/* ---------------------------------------------------------------- small pieces */

const Field = ({ label, hint, error, children, className = "" }) => (
    <div className={className}>
        <label className="block text-sm font-medium text-slate-700">
            {label}
        </label>
        {children}
        {error ? (
            <p className="mt-1.5 flex items-start gap-1.5 text-sm text-red-600">
                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>{error}</span>
            </p>
        ) : (
            hint && <p className="mt-1.5 text-xs text-slate-500">{hint}</p>
        )}
    </div>
);

const StepHeading = ({ step, title, children }) => (
    <div className="mb-4 flex items-baseline gap-3">
        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">
            {step}
        </span>
        <div>
            <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
            {children && (
                <p className="mt-0.5 text-xs text-slate-500">{children}</p>
            )}
        </div>
    </div>
);

/** One of the two things this form can record. Chosen once, up front. */
const ModeCard = ({ active, icon: Icon, title, description, onClick }) => (
    <button
        type="button"
        onClick={onClick}
        aria-pressed={active}
        className={`flex items-start gap-3 rounded-xl border p-4 text-left transition ${
            active
                ? "border-blue-600 bg-blue-50/60 ring-1 ring-blue-600"
                : "border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50"
        }`}
    >
        <span
            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                active ? "bg-blue-600 text-white" : "bg-slate-100 text-slate-500"
            }`}
        >
            <Icon className="h-5 w-5" />
        </span>
        <span className="min-w-0">
            <span className="block text-sm font-semibold text-slate-900">
                {title}
            </span>
            <span className="mt-0.5 block text-xs leading-relaxed text-slate-500">
                {description}
            </span>
        </span>
    </button>
);

/**
 * The staff picker.
 *
 * Everyone is listed with what they are owed, because that is the number the decision
 * turns on. People with nothing owed stay visible and stay selectable — hiding them makes
 * "where did Karim go?" the next question — but they are dimmed and labelled, so the
 * reason is on screen before the click rather than in a rejection afterwards.
 */
const StaffPicker = ({ staff, selectedId, onSelect, error }) => {
    const [query, setQuery] = useState("");

    const matches = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return staff;
        return staff.filter(
            (person) =>
                person.name?.toLowerCase().includes(q) ||
                person.email?.toLowerCase().includes(q),
        );
    }, [staff, query]);

    return (
        <div>
            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Chercher par nom ou e-mail…"
                    aria-label="Chercher un membre du personnel"
                    className="block w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />
            </div>

            <div
                role="radiogroup"
                aria-label="Membre du personnel"
                className="mt-3 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200"
            >
                {matches.length === 0 && (
                    <p className="p-4 text-center text-sm text-slate-500">
                        Personne ne correspond à « {query} ».
                    </p>
                )}

                {matches.map((person) => {
                    const selected = person.id === selectedId;
                    const nothingOwed = person.available <= 0;

                    return (
                        <button
                            key={person.id}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            onClick={() => onSelect(person.id)}
                            className={`flex w-full items-center gap-3 px-3 py-2.5 text-left transition ${
                                selected
                                    ? "bg-blue-50"
                                    : "bg-white hover:bg-slate-50"
                            }`}
                        >
                            <span
                                className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border ${
                                    selected
                                        ? "border-blue-600 bg-blue-600"
                                        : "border-slate-300"
                                }`}
                            >
                                {selected && (
                                    <Check className="h-3 w-3 text-white" />
                                )}
                            </span>

                            <span className="min-w-0 flex-1">
                                <span
                                    className={`block truncate text-sm font-medium ${
                                        nothingOwed
                                            ? "text-slate-400"
                                            : "text-slate-900"
                                    }`}
                                >
                                    {person.name}
                                </span>
                                <span className="block truncate text-xs text-slate-500">
                                    {ROLE_LABEL[person.role] ?? person.role}
                                    {person.email ? ` · ${person.email}` : ""}
                                </span>
                            </span>

                            <span className="shrink-0 text-right">
                                {nothingOwed ? (
                                    <span className="text-xs text-slate-400">
                                        Rien à payer
                                    </span>
                                ) : (
                                    <>
                                        <span className="block text-sm font-semibold tabular-nums text-emerald-700">
                                            {money(person.available)}
                                        </span>
                                        <span className="block text-[11px] text-slate-400">
                                            {person.role === "teacher"
                                                ? "portefeuille"
                                                : "reste du salaire"}
                                        </span>
                                    </>
                                )}
                            </span>
                        </button>
                    );
                })}
            </div>

            {error && (
                <p className="mt-2 flex items-start gap-1.5 text-sm text-red-600">
                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>{error}</span>
                </p>
            )}
        </div>
    );
};

/* ------------------------------------------------------------------- the form */

const PaymentForm = ({
    transaction = null,
    errors: serverErrors = {},
    formType = "create",
    onCancel,
    staff = [],
    expenseCategories = {},
    frequencies = {},
    preselectedUserId = null,
}) => {
    const isEdit = formType === "edit";

    const { data, setData, post, put, processing, transform } = useForm({
        // "staff" or "expense". The concrete type (payment vs salary) is derived from the
        // chosen person on the server and is never posted as a user choice.
        mode: transaction?.type === "expense" ? "expense" : "staff",
        user_id: transaction?.user_id ?? preselectedUserId ?? null,
        amount: transaction?.amount != null ? String(transaction.amount) : "",
        description: transaction?.description ?? "",
        payment_date: transaction?.payment_date
            ? String(transaction.payment_date).slice(0, 10)
            : today(),
        category:
            transaction?.type === "expense"
                ? Object.keys(expenseCategories).includes(transaction.category)
                    ? transaction.category
                    : "other"
                : "classroom",
        custom_category:
            transaction?.type === "expense" &&
            transaction.category &&
            !Object.keys(expenseCategories).includes(transaction.category)
                ? transaction.category
                : "",
        is_recurring: Boolean(transaction?.is_recurring),
        frequency: transaction?.frequency ?? "monthly",
        next_payment_date: transaction?.next_payment_date
            ? String(transaction.next_payment_date).slice(0, 10)
            : "",
    });

    const [showRecurring, setShowRecurring] = useState(
        Boolean(transaction?.is_recurring),
    );

    const selected = useMemo(
        () => staff.find((p) => p.id === data.user_id) ?? null,
        [staff, data.user_id],
    );

    const amount = Number(data.amount) || 0;

    /* The balances arrive computed against the form's date, because an assistant's cap is
       "salary minus what they were paid IN THAT MONTH". Moving the date to another month
       changes every assistant's figure, so the server is asked again — a partial reload of
       just `staff`, not a page load. */
    const [loadedMonth, setLoadedMonth] = useState(monthOf(data.payment_date));

    useEffect(() => {
        const month = monthOf(data.payment_date);
        if (data.mode !== "staff" || !month || month === loadedMonth) return;

        setLoadedMonth(month);
        router.reload({
            only: ["staff"],
            data: { on: data.payment_date },
            preserveState: true,
            preserveScroll: true,
        });
    }, [data.payment_date, data.mode, loadedMonth]);

    /* Client-side checks exist to keep the button honest, not to replace the server —
       App\Support\TransactionRules re-runs all of this inside the DB transaction, which is
       the only place a balance can be trusted. The old form used alert() for this, which
       cannot say WHICH field is wrong and disappears the moment you dismiss it. */
    const clientErrors = useMemo(() => {
        const found = {};

        if (data.mode === "staff") {
            if (!data.user_id) {
                found.user_id = "Choisissez la personne à payer.";
            } else if (selected && !selected.has_profile) {
                found.user_id = `Aucune fiche ${
                    selected.role === "teacher" ? "enseignant" : "assistant"
                } n'est rattachée à ce compte. Vérifiez que l'adresse e-mail est la même des deux côtés.`;
            } else if (selected && selected.available <= 0) {
                found.user_id =
                    selected.role === "teacher"
                        ? "Le portefeuille de cette personne est vide."
                        : `Cette personne a déjà reçu tout son salaire pour ${monthLabel(data.payment_date)}.`;
            }
        } else if (data.category === "other" && !data.custom_category.trim()) {
            found.custom_category = "Précisez la catégorie.";
        }

        if (!data.amount) {
            found.amount = "Saisissez un montant.";
        } else if (amount <= 0) {
            found.amount = "Le montant doit être supérieur à 0.";
        } else if (
            data.mode === "staff" &&
            selected?.available > 0 &&
            amount > selected.available
        ) {
            found.amount = `Maximum ${money(selected.available)}.`;
        }

        if (!data.payment_date) found.payment_date = "Choisissez une date.";

        if (data.is_recurring) {
            if (!data.next_payment_date) {
                found.next_payment_date = "Choisissez la date du prochain paiement.";
            } else if (data.next_payment_date < data.payment_date) {
                found.next_payment_date =
                    "Le prochain paiement ne peut pas précéder celui-ci.";
            }
        }

        return found;
    }, [data, selected, amount]);

    // Server messages win: they are the authoritative answer, and they arrive on the same
    // field keys because TransactionRules throws them as validation errors.
    const errors = { ...clientErrors, ...serverErrors };
    const blocked = Object.keys(clientErrors).length > 0;

    const remaining = selected
        ? Math.max(0, selected.available - amount)
        : null;

    /* Remembers the last description this form generated, so it can tell "the admin has
       not written one" apart from "the admin wrote this". Without it, only an EMPTY
       description was refilled — so after picking a second person the box still described
       the first, and the payment was saved under someone else's name. A ref rather than
       state: it must not itself cause a render. */
    const autoDescription = useRef("");

    const describe = (person, date) =>
        person?.role === "teacher"
            ? `Paiement à ${person.name} depuis son portefeuille`
            : `Salaire de ${monthLabel(date)} — ${person?.name ?? ""}`;

    const handleSelect = (userId) => {
        const person = staff.find((p) => p.id === userId);

        setData((current) => {
            const untouched =
                !current.description?.trim() ||
                current.description === autoDescription.current;

            if (!untouched) {
                // Leave a description the admin typed themselves alone.
                return { ...current, user_id: userId };
            }

            const next = describe(person, current.payment_date);
            autoDescription.current = next;

            return { ...current, user_id: userId, description: next };
        });
    };

    /* The salary description names the month, so moving the date has to move the wording
       with it — otherwise a payment backdated to July goes into the books saying "Salaire
       d'août". Same untouched-only rule as above. */
    useEffect(() => {
        if (data.mode !== "staff" || !selected) return;
        if (data.description !== autoDescription.current) return;

        const next = describe(selected, data.payment_date);
        if (next === data.description) return;

        autoDescription.current = next;
        setData("description", next);
    }, [data.payment_date, data.mode, selected, data.description, setData]);

    /* `mode` is a form-only idea; the server field is `type`. For a staff payment the
       concrete type — `payment` for a teacher, `salary` for an assistant — is decided
       server-side from the role, so what goes on the wire is only a hint and gets
       overwritten in applyPaymentRules(). The form is deliberately not the authority on
       which balance moves: it used to be, and a stale value in its state could record a
       teacher's payout as a salary, which never touches the wallet. */
    transform((fields) => ({
        ...fields,
        type: fields.mode === "expense" ? "expense" : "salary",
        user_id: fields.mode === "expense" ? null : fields.user_id,
        // Cleared rather than left behind: a description written for one mode makes no
        // sense in the other, and stale recurrence fields on a one-off row put it in the
        // recurring queue.
        category: fields.mode === "expense" ? fields.category : null,
        frequency: fields.is_recurring ? fields.frequency : null,
        next_payment_date: fields.is_recurring ? fields.next_payment_date : null,
    }));

    const handleSubmit = (event) => {
        event.preventDefault();
        if (blocked || processing) return;

        const options = { preserveScroll: true };

        if (isEdit && transaction) {
            put(route("transactions.update", transaction.id), options);
        } else {
            post(route("transactions.store"), options);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="mx-auto max-w-3xl">
            {/* Header */}
            <div className="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold text-slate-900">
                        {isEdit
                            ? "Modifier la transaction"
                            : "Nouvelle transaction"}
                    </h2>
                    <p className="mt-1 text-sm text-slate-500">
                        {isEdit
                            ? "Le solde concerné sera ajusté de la différence."
                            : "Trois étapes : qui ou quoi, combien, puis la date."}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onCancel}
                    className="flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                >
                    <ArrowLeft className="h-4 w-4" />
                    Retour
                </button>
            </div>

            <div className="space-y-8">
                {/* ---------------------------------------------- 1. what */}
                <section>
                    <StepHeading step="1" title="Que voulez-vous enregistrer ?" />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ModeCard
                            active={data.mode === "staff"}
                            icon={Users}
                            title="Payer le personnel"
                            description="Un enseignant depuis son portefeuille, ou le salaire d'un assistant."
                            onClick={() => setData("mode", "staff")}
                        />
                        <ModeCard
                            active={data.mode === "expense"}
                            icon={Receipt}
                            title="Enregistrer une dépense"
                            description="Achat, loyer, maintenance — une sortie d'argent qui ne va à personne."
                            onClick={() => setData("mode", "expense")}
                        />
                    </div>
                </section>

                {/* ---------------------------------------------- 2. who / which */}
                <section>
                    {data.mode === "staff" ? (
                        <>
                            <StepHeading step="2" title="Qui payez-vous ?">
                                Le montant disponible est celui de{" "}
                                {monthLabel(data.payment_date)}.
                            </StepHeading>

                            {staff.length === 0 ? (
                                <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                    Aucun enseignant ni assistant enregistré.
                                    Ajoutez-en un avant de créer un paiement.
                                </p>
                            ) : (
                                <StaffPicker
                                    staff={staff}
                                    selectedId={data.user_id}
                                    onSelect={handleSelect}
                                    error={serverErrors.user_id}
                                />
                            )}

                            {/* What will actually happen, in words, before it happens. */}
                            {selected && selected.available > 0 && (
                                <div className="mt-3 flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <Wallet className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                                    <p className="text-sm text-slate-600">
                                        {selected.role === "teacher" ? (
                                            <>
                                                Ce montant sera{" "}
                                                <strong>retiré du portefeuille</strong>{" "}
                                                de {selected.name}. Il contient
                                                aujourd'hui{" "}
                                                {money(selected.available)}.
                                            </>
                                        ) : (
                                            <>
                                                Salaire de{" "}
                                                {monthLabel(data.payment_date)}.
                                                Il reste{" "}
                                                {money(selected.available)} à
                                                verser à {selected.name} pour ce
                                                mois.
                                            </>
                                        )}
                                    </p>
                                </div>
                            )}
                        </>
                    ) : (
                        <>
                            <StepHeading step="2" title="Quelle dépense ?" />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Catégorie" error={errors.category}>
                                    <select
                                        value={data.category}
                                        onChange={(e) =>
                                            setData("category", e.target.value)
                                        }
                                        className="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        {Object.entries(expenseCategories).map(
                                            ([value, label]) => (
                                                <option key={value} value={value}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Field>

                                {data.category === "other" && (
                                    <Field
                                        label="Précisez"
                                        error={errors.custom_category}
                                    >
                                        <input
                                            type="text"
                                            maxLength={100}
                                            value={data.custom_category}
                                            onChange={(e) =>
                                                setData(
                                                    "custom_category",
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="ex. Assurance"
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        />
                                    </Field>
                                )}
                            </div>
                        </>
                    )}
                </section>

                {/* ---------------------------------------------- 3. how much */}
                <section>
                    <StepHeading step="3" title="Combien ?" />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Montant" error={errors.amount}>
                            <div className="relative mt-1">
                                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-slate-400">
                                    DH
                                </span>
                                <input
                                    type="number"
                                    inputMode="decimal"
                                    step="0.01"
                                    min="0"
                                    value={data.amount}
                                    onChange={(e) =>
                                        setData("amount", e.target.value)
                                    }
                                    placeholder="0,00"
                                    aria-invalid={Boolean(errors.amount)}
                                    className={`block w-full rounded-lg py-2 pl-10 pr-3 text-lg font-semibold tabular-nums shadow-sm ${
                                        errors.amount
                                            ? "border-red-300 focus:border-red-500 focus:ring-red-500"
                                            : "border-slate-300 focus:border-blue-500 focus:ring-blue-500"
                                    }`}
                                />
                            </div>
                        </Field>

                        {/* The remainder, live. Teachers never saw this at all before —
                            the old field was rendered only for type "salary", and every
                            teacher payout is type "payment". */}
                        {selected && selected.available > 0 && (
                            <div className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex items-baseline justify-between">
                                    <span className="text-sm text-slate-500">
                                        {selected.role === "teacher"
                                            ? "Restera dans le portefeuille"
                                            : "Restera dû ce mois-ci"}
                                    </span>
                                    <span className="text-base font-semibold tabular-nums text-slate-900">
                                        {money(remaining)}
                                    </span>
                                </div>

                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        className={`h-full rounded-full transition-all ${
                                            amount > selected.available
                                                ? "bg-red-500"
                                                : "bg-blue-600"
                                        }`}
                                        style={{
                                            width: `${Math.min(100, (amount / selected.available) * 100)}%`,
                                        }}
                                    />
                                </div>

                                <button
                                    type="button"
                                    onClick={() =>
                                        setData(
                                            "amount",
                                            String(selected.available),
                                        )
                                    }
                                    className="mt-2 text-xs font-medium text-blue-600 underline-offset-2 hover:underline"
                                >
                                    Tout payer ({money(selected.available)})
                                </button>
                            </div>
                        )}
                    </div>
                </section>

                {/* ---------------------------------------------- 4. when + notes */}
                <section>
                    <StepHeading step="4" title="Date et description" />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Date de paiement"
                            error={errors.payment_date}
                            hint={
                                data.mode === "staff"
                                    ? "Détermine le mois auquel le paiement est rattaché."
                                    : undefined
                            }
                        >
                            <input
                                type="date"
                                value={data.payment_date}
                                onChange={(e) =>
                                    setData("payment_date", e.target.value)
                                }
                                className="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                        </Field>

                        <Field
                            label="Description"
                            error={errors.description}
                            hint="Visible dans l'historique des paiements."
                            className="sm:col-span-2"
                        >
                            <textarea
                                rows={2}
                                maxLength={500}
                                value={data.description}
                                onChange={(e) =>
                                    setData("description", e.target.value)
                                }
                                placeholder="À quoi correspond cette transaction ?"
                                className="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                        </Field>
                    </div>

                    {/* Recurrence is collapsed: most transactions are one-off, and an
                        always-open block of frequency controls was a large part of what
                        made this screen look complicated. */}
                    <div className="mt-4 rounded-lg border border-slate-200">
                        <button
                            type="button"
                            onClick={() => setShowRecurring((open) => !open)}
                            aria-expanded={showRecurring}
                            className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left"
                        >
                            <Repeat className="h-4 w-4 shrink-0 text-slate-400" />
                            <span className="flex-1 text-sm font-medium text-slate-700">
                                Répéter automatiquement
                            </span>
                            {data.is_recurring && (
                                <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                                    Activé
                                </span>
                            )}
                            <ChevronDown
                                className={`h-4 w-4 text-slate-400 transition-transform ${
                                    showRecurring ? "rotate-180" : ""
                                }`}
                            />
                        </button>

                        {showRecurring && (
                            <div className="space-y-4 border-t border-slate-100 p-3">
                                <label className="flex items-start gap-2.5">
                                    <input
                                        type="checkbox"
                                        checked={data.is_recurring}
                                        onChange={(e) =>
                                            setData(
                                                "is_recurring",
                                                e.target.checked,
                                            )
                                        }
                                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    <span className="text-sm text-slate-600">
                                        Créer une récurrence. Elle n'est jamais
                                        payée toute seule : elle apparaît dans
                                        « Traiter les récurrents », où vous la
                                        validez.
                                    </span>
                                </label>

                                {data.is_recurring && (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            label="Fréquence"
                                            error={errors.frequency}
                                        >
                                            <select
                                                value={data.frequency}
                                                onChange={(e) =>
                                                    setData(
                                                        "frequency",
                                                        e.target.value,
                                                    )
                                                }
                                                className="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            >
                                                {Object.entries(frequencies).map(
                                                    ([value, label]) => (
                                                        <option
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </Field>

                                        <Field
                                            label="Prochain paiement"
                                            error={errors.next_payment_date}
                                        >
                                            <input
                                                type="date"
                                                min={data.payment_date}
                                                value={data.next_payment_date}
                                                onChange={(e) =>
                                                    setData(
                                                        "next_payment_date",
                                                        e.target.value,
                                                    )
                                                }
                                                className="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            />
                                        </Field>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </section>
            </div>

            {/* ---------------------------------------------- confirm */}
            <div className="sticky bottom-0 -mx-3 mt-8 border-t border-slate-200 bg-white/95 px-3 py-4 backdrop-blur sm:-mx-6 sm:px-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    {/* Says what is about to happen in one sentence, so the last thing
                        read before pressing the button is the outcome, not a field name. */}
                    <p className="text-sm text-slate-600">
                        {blocked ? (
                            <span className="flex items-center gap-1.5 text-slate-400">
                                <AlertTriangle className="h-4 w-4" />
                                Complétez les champs signalés en rouge.
                            </span>
                        ) : data.mode === "expense" ? (
                            <>
                                Dépense de{" "}
                                <strong className="text-slate-900">
                                    {money(amount)}
                                </strong>{" "}
                                le {data.payment_date}.
                            </>
                        ) : (
                            <>
                                <strong className="text-slate-900">
                                    {money(amount)}
                                </strong>{" "}
                                à {selected?.name}. Il restera{" "}
                                <strong className="text-slate-900">
                                    {money(remaining)}
                                </strong>
                                .
                            </>
                        )}
                    </p>

                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={onCancel}
                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                        >
                            Annuler
                        </button>
                        <button
                            type="submit"
                            /* Disabled while in flight as well as while invalid: this
                               moves money, and a double click used to send two payments. */
                            disabled={blocked || processing}
                            className="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:shadow-none"
                        >
                            <Banknote className="h-4 w-4" />
                            {processing
                                ? "Enregistrement…"
                                : isEdit
                                  ? "Enregistrer les modifications"
                                  : "Enregistrer"}
                        </button>
                    </div>
                </div>
            </div>
        </form>
    );
};

export default PaymentForm;
