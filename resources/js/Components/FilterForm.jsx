import React from 'react';

const FilterForm = ({ schools, classes, levels, filters, onFilterChange }) => {
  return (
    <div className="bg-white p-4 rounded-lg shadow-md mb-4">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {/* School Filter */}
        <div>
          <label className="block text-sm font-medium text-gray-700">School</label>
          <select
            name="school"
            value={filters.school}
            onChange={onFilterChange}
            className="mt-1 block w-full p-2 border border-gray-300 rounded-md"
          >
            <option value="">Select School</option>
            {schools.map((school) => (
              <option key={school.id} value={school.id}>
                {school.name}
              </option>
            ))}
          </select>
        </div>

        {/* Class Filter */}
        <div>
          <label className="block text-sm font-medium text-gray-700">Class</label>
          <select
            name="class"
            value={filters.class}
            onChange={onFilterChange}
            className="mt-1 block w-full p-2 border border-gray-300 rounded-md"
          >
            <option value="">Select Class</option>
            {classes.map((cls) => (
              <option key={cls.id} value={cls.id}>
                {cls.name}
              </option>
            ))}
          </select>
        </div>

        {/* Level Filter */}
        <div>
          <label className="block text-sm font-medium text-gray-700">Level</label>
          <select
            name="level"
            value={filters.level}
            onChange={onFilterChange}
            className="mt-1 block w-full p-2 border border-gray-300 rounded-md"
          >
            <option value="">Select Level</option>
            {levels.map((level) => (
              <option key={level.id} value={level.id}>
                {level.name}
              </option>
            ))}
          </select>
        </div>
      </div>
    </div>
  );
};

export default FilterForm;