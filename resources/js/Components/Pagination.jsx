const Pagination = ({ links }) => {
    if (!links || !Array.isArray(links)) {
      return null; // Return nothing if links are not available
    }
  
    return (
      <div className="p-4 flex items-center justify-between text-gray-500">
        <button
          disabled={!links.prev}
          onClick={() => window.location = links.prev}
          className="py-2 px-4 rounded-md bg-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Prev
        </button>
        <div className="flex items-center gap-2 text-sm">
          {links.map((link, index) => (
            <button
              key={index}
              onClick={() => window.location = link.url}
              className={`px-2 rounded-sm ${link.active ? 'bg-lamaSky' : ''}`}
              dangerouslySetInnerHTML={{ __html: link.label }}
            />
          ))}
        </div>
        <button
          disabled={!links.next}
          onClick={() => window.location = links.next}
          className="py-2 px-4 rounded-md bg-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Next
        </button>
      </div>
    );
  };
  
  export default Pagination;