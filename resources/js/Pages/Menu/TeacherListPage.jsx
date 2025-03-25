import FormModal from "@/Components/FormModal";
import Pagination from "@/Components/Pagination";
import Table from "@/Components/Table";
import TableSearch from "@/Components/TableSearch";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Link ,router,usePage} from '@inertiajs/react';
import { Eye, UserRoundPen } from "lucide-react";
const columns = [
  {
    header: "Info",
    accessor: "info",
  },
  {
    header: "Teacher ID",
    accessor: "teacherId",
    className: "hidden md:table-cell",
  },
  {
    header: "Subjects",
    accessor: "subjects",
    className: "hidden md:table-cell",
  },
  {
    header: "Classes",
    accessor: "classes",
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
    header: "Actions",
    accessor: "action",
  },
];

const TeacherListPage = ({teachers,subjects,classes,schools}) => {
 console.log(teachers)
 console.log('subjects ',subjects)
 
  const role = usePage().props.auth.user.role;

  const renderRow = (item) => (
    
    <tr
   
      key={item.id}
      className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight"
    >
      <td className="flex items-center gap-4 p-4 cursor-pointer"  onClick={() => router.visit(`/teachers/${item.id}`)}>
        <img
          src={item.profile_image ? item.profile_image : "/teacherPrfile2.png"}
          alt={item.name}
          width={40}
          height={40}
          className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
        />
        <div className="flex flex-col">
          <h3 className="font-semibold">{item.name}</h3>
          <p className="text-xs text-gray-500">{item?.email}</p>
        </div>
      </td>
      <td className="hidden md:table-cell">{item.id}</td>
      <td className="hidden md:table-cell">{item.subjects.map((subject) => subject.name).join(", ") }</td>
      <td className="hidden md:table-cell">{item.classes.map((group) => group.name).join(", ")}</td>
      <td className="hidden md:table-cell">{item.phone}</td>
      <td className="hidden md:table-cell">{item.address}</td>
      <td>
        <div className="flex items-center gap-2">
          <Link href={`/teachers/${item.id}`}>
            <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
            <Eye className="w-4 h-4 text-white"/>
            </button>
          </Link>
          {role === "admin" && (
            <>
            <FormModal table="teacher" type="update" id={item.id} data={item} schools={schools} subjects={subjects} classes={classes}/>
            <FormModal table="teacher" type="delete" id={item.id} route="teachers"/>
           
            
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
        <h1 className="hidden md:block text-lg font-semibold">All Teachers</h1>
        <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
          <TableSearch routeName="teachers.index"/>
          <div className="flex items-center gap-4 self-end">
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/filter.png" alt="" width={14} height={14} />
            </button>
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/sort.png" alt="" width={14} height={14} />
            </button>
            {role === "admin" && <FormModal table="teacher" type="create" schools={schools} subjects={subjects} classes={classes}/>}
          </div>
        </div>
      </div>
      {/* LIST */}
      <Table columns={columns} renderRow={renderRow} data={teachers.data} />
      {/* PAGINATION */}
        <Pagination links={teachers.links} />
    </div>
  );
};
TeacherListPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default TeacherListPage;
