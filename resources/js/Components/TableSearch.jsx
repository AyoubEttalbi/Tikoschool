import { router } from '@inertiajs/react';
import { useState } from 'react';

const TableSearch = ({ routeName }) => {
  const [searchTerm, setSearchTerm] = useState('');

  const handleSearch = (e) => {
    const value = e.target.value;
    setSearchTerm(value); // Update the local state

    // Trigger a GET request with the search term
    router.get(
      route(routeName), // Route name
      { search: value }, // Query parameters
      { preserveState: true, replace: true } // Options
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
        onChange={handleSearch} // Trigger search on every keystroke
      />
    </div>
  );
};

export default TableSearch;