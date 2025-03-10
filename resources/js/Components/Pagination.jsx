import { Link, usePage } from '@inertiajs/react';

const Pagination = ({ links }) => {
    const { search } = usePage().props; // Get search query from Inertia props

    return (
        <div className="p-4 flex items-center justify-center text-gray-500">
            <div className="flex items-center gap-2 text-sm">
                {links.map((link, index) => {
                    // If the link is a valid URL, append search term to it
                    let url = link.url;
                    if (search) {
                        // Append search query if it's present
                        url = `${link.url}&search=${search}`;
                    }

                    return link.url ? (
                        <Link
                            key={index}
                            href={url}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                            className={`px-2 rounded-sm ${link.active ? 'bg-lamaSky font-bold' : ''}`}
                        />
                    ) : (
                        <span
                            key={index}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                            className="rounded-sm text-slate-300 py-2 px-4 text-xs font-semibold opacity-50 cursor-not-allowed"
                        ></span>
                    );
                })}
            </div>
        </div>
    );
};

export default Pagination;