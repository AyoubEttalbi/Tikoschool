import { role } from "@/lib/data";
import { Link } from "@inertiajs/react";
import FormModal from "../../Components/FormModal";
import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { UserRoundPen } from "lucide-react";

const columns = [
  { header: "Name", accessor: "name" },
  { header: "Level", accessor: "level", className: "hidden md:table-cell" },
  { header: "Number of Students", accessor: "numStudents", className: "hidden md:table-cell" },
  { header: "Number of Teachers", accessor: "numTeachers", className: "hidden lg:table-cell" },
  { header: "Actions", accessor: "action" },
];

const classesData = [
  { id: 1, name: "2BAC SVT G1", level: "2BAC", numStudents: 22, numTeachers: 2 },
  { id: 2, name: "1BAC PC G2", level: "1BAC", numStudents: 18, numTeachers: 1 },
  { id: 3, name: "3BAC Math G3", level: "3BAC", numStudents: 25, numTeachers: 3 },
  { id: 4, name: "2BAC Science G4", level: "2BAC", numStudents: 20, numTeachers: 2 },
  { id: 5, name: "1BAC English G5", level: "1BAC", numStudents: 19, numTeachers: 1 },
];


const ClassesPage = () => {
  const renderRow = (classe) => (
    <tr
      key={classe.id}
      className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight"
    >
      <td className="p-4 font-semibold">{classe.name}</td>
      <td className="hidden md:table-cell">{classe.level}</td>
      <td className="hidden md:table-cell text-gray-500">{classe.numStudents}</td>
      <td className="hidden lg:table-cell text-gray-500">{classe.numTeachers}</td>
      <td>
        <div className="flex items-center gap-2">
          <Link href={`/classes/${classe.id}`}>
            <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
              <img src="/view.png" alt="" width={16} height={16} />
            </button>
          </Link>
          {role === "admin" &&<>
          <FormModal table="class" type="update" id={classe.id} /> 
          <FormModal table="class" type="delete" id={classe.id} /> 
          </> }
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
          <TableSearch />
          <div className="flex items-center gap-4 self-end">
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/filter.png" alt="" width={14} height={14} />
            </button>
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/sort.png" alt="" width={14} height={14} />
            </button>
            {role === "admin" && <FormModal table="class" type="create" />}
          </div>
        </div>
      </div>
      {/* LIST */}
      <Table columns={columns} renderRow={renderRow} data={classesData} />
      {/* PAGINATION */}
      <Pagination />
    </div>
  );
};

ClassesPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default ClassesPage;