import { router, usePage, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Eye, RotateCcw } from "lucide-react";
import FormModal from "../../Components/FormModal";
import FilterForm from '@/Components/FilterForm';
import { motion } from "framer-motion";

const columns = [
  {
    header: "Info",
    accessor: "info",
  },
  {
    header: "Student ID",
    accessor: "studentId",
    className: "hidden md:table-cell",
  },
  {
    header: "Class",
    accessor: "class",
    className: "hidden md:table-cell",
  },
  {
    header: "Phone",
    accessor: "phone",
    className: "hidden lg:table-cell",
  },
  {
    header: "Address",
    accessor: "address",
    className: "hidden lg:table-cell",
  },
  {
    header: "Membership status",
    accessor: "membershipStatus",
    className: "hidden lg:table-cell",
  },
  {
    header: "Actions",
    accessor: "action",
    className: "text-center",
  },
];

const StudentListPage = ({ students, Allclasses, Alllevels, Allschools, filters: initialFilters, Allmemberships }) => {
  const role = usePage().props.auth.user.role;
  console.log("Allmemberships", Allmemberships);
  // State for filters and search
  const [filters, setFilters] = useState({
    school: initialFilters.school || '',
    class: initialFilters.class || '',
    level: initialFilters.level || '',
    search: initialFilters.search || '',
  });

  const [showFilters, setShowFilters] = useState(false);

  // Debounced function to apply filters
  useEffect(() => {
    const timeoutId = setTimeout(() => {
      router.get(
        route('students.index'),
        { ...filters }, // Include all filters and search term
        { preserveState: true, replace: true, preserveScroll: true }
      );
    }, 300); // Debounce for 300ms

    return () => clearTimeout(timeoutId); // Cleanup on filter change
  }, [filters]);

  // Handle filter changes
  const handleFilterChange = (e) => {
    const { name, value } = e.target;

    // Update the filters state
    const newFilters = { ...filters, [name]: value };
    setFilters(newFilters);

    // Use Inertia to navigate with the new filters while resetting to page 1
    router.get(route('students.index'), {
      ...newFilters,
      page: 1
    }, {
      preserveState: true,
      replace: true
    });
  };

  // Clear filters and reset the page
  const clearFilters = () => {
    setFilters({
      school: '',
      class: '',
      level: '',
      search: '',
    });

    // Navigate to the index route without any query parameters
    router.get(
      route('students.index'),
      {}, // No query parameters
      { preserveState: false, replace: true, preserveScroll: true }
    );
  };

  // Toggle visibility of filters
  const toggleFilters = () => {
    setShowFilters(!showFilters);
  };

  // Render table rows
  const renderRow = (item) => (
    <tr
      key={item.id}
      className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight"
    >
      <td className="flex items-center gap-4 p-4">
        <img
          src="/studentProfile.png"
          alt={item.name}
          width={40}
          height={40}
          className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
        />
        <div className="flex flex-col">
          <h3 className="font-semibold">{item.name}</h3>
          <p className="text-xs text-gray-500">{Alllevels.find((level) => level.id === item.levelId)?.name}</p>
        </div>
      </td>
      <td className="hidden md:table-cell">{item.studentId}</td>
      <td className="hidden md:table-cell">{Allclasses.find((group) => group.id === item.classId)?.name}</td>
      <td className="hidden md:table-cell">{item.phone}</td>
      <td className="hidden md:table-cell">{item.address}</td>
      <td className="hidden md:table-cell w-1/12">
  {Allmemberships.filter(membership => membership.student_id === item.id).length > 0 ? (
    <div className="flex flex-col space-y-1">
      {/* Payment ratio with colored stats */}
      <div className="flex items-center justify-between">
        <div className="flex items-center">
          <span className="font-semibold text-emerald-600">
            {Allmemberships.filter(membership => membership.student_id === item.id && membership.payment_status === "paid").length}
          </span>
          <span className="mx-1 text-gray-400">/</span>
          <span className="font-medium text-gray-700">
            {Allmemberships.filter(membership => membership.student_id === item.id).length}
          </span>
        </div>
      </div>

      {/* Progress bar with animation */}
      {(() => {
        const paid = Allmemberships.filter(membership => membership.student_id === item.id && membership.payment_status === "paid").length;
        const total = Allmemberships.filter(membership => membership.student_id === item.id).length;
        const percentage = total > 0 ? (paid / total) * 100 : 0;

        let bgColorClass = "bg-gray-300";
        if (percentage === 100) bgColorClass = "bg-emerald-500";
        else if (percentage >= 75) bgColorClass = "bg-green-500";
        else if (percentage >= 50) bgColorClass = "bg-amber-500";
        else if (percentage > 0) bgColorClass = "bg-orange-500";
        else bgColorClass = "bg-red-500";

        return (
          <div className="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
            <motion.div
              className={`h-full rounded-full ${bgColorClass}`}
              initial={{ width: "0%" }}
              animate={{ width: `${percentage}%` }}
              transition={{ duration: 0.8, ease: "easeInOut" }} // Smooth animation
            ></motion.div>
          </div>
        );
      })()}
    </div>
  ) : "-"}
</td>
      <td className=' p-4'>
        <div className="flex items-center gap-2 justify-center">
          <Link href={`students/${item.id}`}>
            <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
              <Eye className="w-4 h-4 text-white" />
            </button>
          </Link>
          {role === "admin" && (
            <>
              <FormModal table="student" type="update" data={item} levels={Alllevels} classes={Allclasses} schools={Allschools} />
              <FormModal table="student" type="delete" id={item.id} route="students" />
            </>
          )}
        </div>
      </td>
    </tr>
  );

  return (
    <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
      {/* TOP */}
      <div className="flex items-center justify-between">
        <h1 className="hidden md:block text-lg font-semibold">All Students</h1>
        <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
          <TableSearch
            routeName="students.index"
            value={filters.search}
            onChange={(value) => setFilters((prev) => ({ ...prev, search: value }))}
          />

          <div className="flex items-center gap-4 self-end">
            <button
              onClick={clearFilters}
              className="w-8 h-8 flex  items-center justify-center rounded-full bg-lamaYellow"
            >
              <RotateCcw className="w-4 h-4 text-black" />

            </button>
            <button
              onClick={toggleFilters}
              className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow"
            >
              <img src="/filter.png" alt="Filter" width={14} height={14} />
            </button>

            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/sort.png" alt="Sort" width={14} height={14} />
            </button>
            {role === "admin" && (
              <FormModal table="student" type="create" levels={Alllevels} classes={Allclasses} schools={Allschools} />
            )}
          </div>
        </div>
      </div>

      {/* FILTER FORM */}
      {showFilters && (
        <FilterForm
          schools={Allschools}
          classes={Allclasses}
          levels={Alllevels}
          filters={filters}
          onFilterChange={handleFilterChange}
        />
      )}

      {/* LIST */}
      <Table columns={columns} renderRow={renderRow} data={students.data} />
      <Pagination links={students.links} filters={filters} />
    </div>
  );
};

StudentListPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default StudentListPage;