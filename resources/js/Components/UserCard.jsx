import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { useState } from "react";

const UserCard = ({ type, counts, totalCount, schools }) => {
  const [school, setSchool] = useState("all");
  const [open, setOpen] = useState(false);

  // Get the count for the selected school
  const count = school === "all"
    ? totalCount // Use the total count from the backend
    : counts[school]?.count || 0; // Get count for the selected school

  return (
    <div className="rounded-2xl odd:bg-lamaPurple even:bg-lamaYellow p-4 flex-1 min-w-[130px] relative">
      <div className="flex justify-between items-center">
        <span className="text-[10px] bg-white px-2 py-1 rounded-full text-green-600">
          2024/25
        </span>
        <button onClick={() => setOpen(!open)}>
          <img src="/more.png" alt="" width={20} height={20} />
        </button>
      </div>
      <h1 className="text-2xl font-semibold my-4">{count}</h1>
      <h2 className="capitalize text-sm font-medium text-gray-500">{type}s</h2>

      {/* Dropdown */}
      {open && (
        <div className="absolute top-8 right-2 mt-2 w-48 bg-white rounded-lg shadow-lg z-10">
          <Select value={school} onValueChange={(value) => {
            setSchool(value);
            setOpen(false); // Close the dropdown after selection
          }}>
            <SelectTrigger className="w-full bg-white border-none shadow-none">
              <SelectValue placeholder="Select School" />
            </SelectTrigger>
            <SelectContent className="bg-white rounded-lg shadow-md">
              <SelectItem value="all" className="cursor-pointer hover:bg-gray-100 p-2">
                All Schools
              </SelectItem>
              {schools.map((school) => (
                <SelectItem key={school.id} value={school.id} className="cursor-pointer hover:bg-gray-100 p-2">
                  {school.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
    </div>
  );
};

export default UserCard;