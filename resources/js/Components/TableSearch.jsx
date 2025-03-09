import { useState, useEffect } from "react";
import { router, usePage } from "@inertiajs/react";

const TableSearch = ({routeName}) => {
    const { students } = usePage().props; // Get existing students data
    const [query, setQuery] = useState("");

    useEffect(() => {
        const delayDebounceFn = setTimeout(() => {
            router.get(route(`${routeName}.index`), { search: query }, {
                preserveState: true,
                replace: true,
                only: ["students"], // Update only students
            });
        }, 300); // 300ms debounce

        return () => clearTimeout(delayDebounceFn);
    }, [query]);

    return (
        <div className="w-full md:w-auto flex items-center gap-2 text-xs rounded-full ring-[1.5px] ring-gray-300 px-2">
            <img src="/search.png" alt="search" width={14} height={14} />
            <input
                type="text"
                placeholder="Search..."
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                className="w-[200px] p-2 bg-transparent outline-none border-none focus:ring-0"
            />
        </div>
    );
};

export default TableSearch;