import { role } from "@/lib/data";
import { Link } from "@inertiajs/react";
import FormModal from "../../Components/FormModal";
import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { UserRoundPen } from "lucide-react";
import { useEffect, useState } from "react"; // Import useState and useEffect

const columns = [
  { header: "Name", accessor: "name" },
  { header: "Level", accessor: "level", className: "hidden md:table-cell" },
  { header: "Number of Students", accessor: "numStudents", className: "hidden md:table-cell" },
  { header: "Number of Teachers", accessor: "numTeachers", className: "hidden lg:table-cell" },
  { header: "Actions", accessor: "action" },
];

const ClassesPage = ({ classes: initialClasses, levels }) => {
  const [classes, setClasses] = useState(initialClasses);

  // Fetch student and teacher counts for each class
  useEffect(() => {
    const fetchCounts = async () => {
      const updatedClasses = await Promise.all(
        initialClasses.map(async (classe) => {
          const studentCount = await fetchStudentCount(classe.id);
          const teacherCount = await fetchTeacherCount(classe.id);
          return {
            ...classe,
            number_of_students: studentCount,
            number_of_teachers: teacherCount,
          };
        })
      );
      setClasses(updatedClasses);
    };

    fetchCounts();
  }, [initialClasses]);

  // Fetch student count for a class
  const fetchStudentCount = async (classId) => {
    const response = await fetch(`/classes/${classId}/student-count`);
    const data = await response.json();
    return data.studentCount;
  };

  // Fetch teacher count for a class
  const fetchTeacherCount = async (classId) => {
    const response = await fetch(`/classes/${classId}/teacher-count`);
    const data = await response.json();
    return data.teacherCount;
  };

  const renderRow = (classe) => (
    <tr
      key={classe.id}
      className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight"
    >
      <td className="p-4 font-semibold">{classe.name}</td>
      <td className="hidden md:table-cell">{classe.level.name}</td> {/* Access level.name */}
      <td className="hidden md:table-cell text-gray-500">{classe.number_of_students}</td>
      <td className="hidden lg:table-cell text-gray-500">{classe.number_of_teachers}</td>
      <td>
        <div className="flex items-center gap-2">
          <Link href={`/classes/${classe.id}`}>
            <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
              <img src="/view.png" alt="" width={16} height={16} />
            </button>
          </Link>
          {role === "admin" && (
            <>
              <FormModal table="class" type="update" id={classe.id} data={classe} groups={classes} levels={levels} />
              <FormModal table="class" type="delete" id={classe.id} route="classes" />
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
        <h1 className="hidden md:block text-lg font-semibold">All Classes</h1>
        <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
          {/* <TableSearch routeName="classes"/> */}
          <div className="flex items-center gap-4 self-end">
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/filter.png" alt="" width={14} height={14} />
            </button>
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/sort.png" alt="" width={14} height={14} />
            </button>
            {role === "admin" && <FormModal table="class" type="create" levels={levels} />}
          </div>
        </div>
      </div>
      {/* LIST */}
      <Table columns={columns} renderRow={renderRow} data={classes} />
    </div>
  );
};

ClassesPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default ClassesPage;