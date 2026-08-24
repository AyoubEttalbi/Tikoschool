import { Link } from "@inertiajs/react";

/**
 * A titled panel of the cockpit. `seeAll` renders the "Voir tout" escape route
 * in the header — a list longer than its preview must always have one.
 */
export default function SectionCard({ icon: Icon, title, seeAll, seeAllLabel = "Voir tout", children }) {
    return (
        <section className="bg-white rounded-md border border-gray-100 shadow-sm flex flex-col">
            <header className="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-100">
                <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    {Icon && <Icon className="w-4 h-4 text-lamaSky" aria-hidden="true" />}
                    {title}
                </h2>
                {seeAll && (
                    <Link
                        href={seeAll}
                        className="text-xs font-semibold text-sky-700 hover:text-sky-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky rounded"
                    >
                        {seeAllLabel}
                    </Link>
                )}
            </header>
            <div className="p-4 flex-1">{children}</div>
        </section>
    );
}
