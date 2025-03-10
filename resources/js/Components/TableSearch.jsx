import { useState, useEffect } from "react";
import { router, usePage } from "@inertiajs/react";

const TableSearch = ({ routeName }) => {
    const { search } = usePage().props;  // Get search from Inertia props
    const [query, setQuery] = useState(search || ""); // Initialize with existing search term from Inertia

    useEffect(() => {
        // If the query changes (after typing or changing), send the request
        if (query === search) return; // Avoid sending request if the search term hasn't changed

        const delayDebounceFn = setTimeout(() => {
            router.get(route(`${routeName}.index`), { search: query }, {
                preserveState: true,
                replace: true,
                only: ["students"], // Update only students
            });
        }, 300); // 300ms debounce

        return () => clearTimeout(delayDebounceFn);
    }, [query, search]); // Watch for changes in both query and search

    return (
        <div className="w-full md:w-auto flex items-center gap-2 text-xs rounded-full ring-[1.5px] ring-gray-300 px-2">
            <img src="/search.png" alt="search" width={14} height={14} />
            <input
                type="text"
                placeholder="Search..."
                value={query || ""}
                onChange={(e) => setQuery(e.target.value)}
                className="w-[200px] p-2 bg-transparent outline-none border-none focus:ring-0"
            />
        </div>
    );
};

export default TableSearch;