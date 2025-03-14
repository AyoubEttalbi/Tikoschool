import { Link } from "@inertiajs/react";

const Pagination = ({ links, filters }) => {
  if (!links || !Array.isArray(links)) {
    return null; 
  }


  const addFiltersToUrl = (url) => {
    if (!url) return url;
    const urlObj = new URL(url);
    if (filters) {
      if (filters.school) urlObj.searchParams.set('school', filters.school);
      if (filters.class) urlObj.searchParams.set('class', filters.class);
      if (filters.level) urlObj.searchParams.set('level', filters.level);
      
    }
    return urlObj.toString();
  };

  return (
    <div className="p-4 flex items-center justify-center text-gray-500">
      <div className="flex items-center gap-2 text-sm">
        {links.map((link, index) => {
          // Only modify the URL if it exists
          const modifiedUrl = link.url ? addFiltersToUrl(link.url) : null;
          
          return link.url ? (
            <Link
              key={index}
              href={modifiedUrl}
              dangerouslySetInnerHTML={{ __html: link.label }}
              className={`px-2 rounded-sm ${link.active ? 'bg-lamaSky font-bold' : ''}`}
              preserveState={true}
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