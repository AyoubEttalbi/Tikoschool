import { Link } from "@inertiajs/react";

/**
 * One number the assistant must act on. The whole card is the click target —
 * a KPI that goes nowhere is decoration, and the cockpit has no room for it.
 * tone: "sky" (default) | "amber" | "red" | "green"
 */
const tones = {
    sky: {
        chip: "bg-lamaSkyLight text-lamaSky border-lamaSky/30",
    },
    amber: {
        chip: "bg-amber-50 text-amber-600 border-amber-200",
    },
    red: {
        chip: "bg-red-50 text-red-600 border-red-200",
    },
    green: {
        chip: "bg-green-50 text-green-600 border-green-200",
    },
};

export default function KpiCard({ icon: Icon, label, value, sub, href, tone = "sky", badge }) {
    const classes = tones[tone] ?? tones.sky;

    const body = (
        <div className="bg-white rounded-md border border-gray-100 shadow-sm p-4 h-full flex flex-col gap-3 transition-shadow group-hover:shadow-md">
            <div className="flex items-center justify-between">
                <span
                    className={`h-9 w-9 rounded-md border flex items-center justify-center ${classes.chip}`}
                >
                    <Icon className="w-5 h-5" aria-hidden="true" />
                </span>
                {badge > 0 && (
                    <span className="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-red-500 text-white text-[11px] font-bold tabular-nums">
                        {badge}
                    </span>
                )}
            </div>
            <div>
                <p className="text-xs font-medium uppercase tracking-wide text-gray-400">
                    {label}
                </p>
                <p className="mt-1 text-xl xl:text-2xl font-semibold text-gray-900 tabular-nums leading-tight">
                    {value}
                </p>
                {sub && <p className="mt-0.5 text-xs text-gray-500">{sub}</p>}
            </div>
        </div>
    );

    if (!href) {
        return body;
    }

    return (
        // The focus ring lives HERE, on the focusable element — a focus-visible
        // class on the inner div never fires because the div is not focusable.
        <Link
            href={href}
            className="block rounded-md outline-none focus-visible:ring-2 focus-visible:ring-lamaSky focus-visible:ring-offset-2"
        >
            {body}
        </Link>
    );
}
