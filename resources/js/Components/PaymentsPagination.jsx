import React from "react";

const Pagination = ({ currentPage, totalPages, onPageChange }) => {
    if (totalPages <= 1) return null;

    const getVisiblePages = () => {
        if (totalPages <= 7) return Array.from({ length: totalPages }, (_, i) => i + 1);
        const delta = 2;
        const range = [];
        const rangeWithDots = [];

        // Always show first, last, and delta around current
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) {
                range.push(i);
            }
        }

        let prev = null;
        for (const page of range) {
            if (prev !== null && page - prev > 1) {
                // Gap >1 needs ellipsis, but if gap is exactly 2, show the missing page instead of dots
                if (page - prev === 2) {
                    rangeWithDots.push(prev + 1);
                } else {
                    rangeWithDots.push("...");
                }
            }
            rangeWithDots.push(page);
            prev = page;
        }

        return rangeWithDots;
    };

    const visiblePages = getVisiblePages();

    return (
        <div className="flex justify-center">
            <nav className="inline-flex items-center gap-1 rounded-md flex-wrap" aria-label="Pagination">
                <button
                    onClick={() => onPageChange(currentPage - 1)}
                    disabled={currentPage === 1}
                    className={`px-3 py-1.5 border rounded-md text-sm font-medium transition-colors ${
                        currentPage === 1
                            ? "text-gray-300 bg-white border-gray-200 cursor-not-allowed"
                            : "text-gray-700 bg-white border-gray-300 hover:bg-gray-50"
                    }`}
                >
                    Précédent
                </button>
                <div className="inline-flex items-center gap-1">
                    {visiblePages.map((page, idx) => {
                        if (page === "...") {
                            return (
                                <span key={`dots-${idx}`} className="px-2 py-1 text-gray-400 select-none">
                                    ...
                                </span>
                            );
                        }
                        const isActive = currentPage === page;
                        return (
                            <button
                                key={page}
                                onClick={() => onPageChange(page)}
                                className={`min-w-[36px] px-3 py-1.5 border rounded-md text-sm font-medium transition-colors ${
                                    isActive
                                        ? "text-white bg-blue-600 border-blue-600 font-bold"
                                        : "text-gray-700 bg-white border-gray-300 hover:bg-gray-50"
                                }`}
                                aria-current={isActive ? "page" : undefined}
                            >
                                {page}
                            </button>
                        );
                    })}
                </div>
                <button
                    onClick={() => onPageChange(currentPage + 1)}
                    disabled={currentPage === totalPages}
                    className={`px-3 py-1.5 border rounded-md text-sm font-medium transition-colors ${
                        currentPage === totalPages
                            ? "text-gray-300 bg-white border-gray-200 cursor-not-allowed"
                            : "text-gray-700 bg-white border-gray-300 hover:bg-gray-50"
                    }`}
                >
                    Suivant
                </button>
            </nav>
        </div>
    );
};

export default Pagination;
