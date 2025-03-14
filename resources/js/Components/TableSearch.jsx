import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const TableSearch = ({ routeName }) => {
  const pageProps = usePage().props;
  const [searchTerm, setSearchTerm] = useState(pageProps.search || '');

  const handleSearch = (e) => {
    const value = e.target.value;
    setSearchTerm(value);

    // Preserve existing filters while updating the search term
    router.get(
      route(routeName),
      { ...pageProps.filters, search: value }, // Include existing filters and update search
      { preserveState: true, replace: true, preserveScroll: true }
    );
  };

  return (
    <div className="w-full md:w-auto flex items-center gap-2 text-xs rounded-full ring-[1.5px] ring-gray-300 px-2">
      <img src="/search.png" alt="" width={14} height={14} />
      <input
        type="text"
        placeholder="Search..."
        className="w-[200px] p-2 bg-transparent outline-none border-none focus:ring-0"
        value={searchTerm}
        onChange={handleSearch}
      />
    </div>
  );
};

export default TableSearch;