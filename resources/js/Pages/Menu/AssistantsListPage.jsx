import { role } from "@/lib/data";
import { Link } from '@inertiajs/react';

import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import FormModal from "../../Components/FormModal";

// Define table columns for assistants
const columns = [
  {
    header: "Info",
    accessor: "info",
  },
  {
    header: "Phone",
    accessor: "phone",
    className: "hidden md:table-cell",
  },
  {
    header: "Email",
    accessor: "email",
    className: "hidden md:table-cell",
  },
  {
    header: "Address",
    accessor: "address",
    className: "hidden lg:table-cell",
  },
  {
    header: "Status",
    accessor: "status",
    className: "hidden md:table-cell",
  },
  {
    header: "Actions",
    accessor: "action",
  },
];

const AssistantsListPage = ({assistants = [], schools}) => {
  console.log("assistants list",assistants);
  const renderRow = (assistant) => (
    <tr
      key={assistant.id}
      className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight"
    >
      <td className="flex items-center gap-4 p-4">
        <img
          src="/assistantProfile.png"
          alt={`${assistant.first_name} ${assistant.last_name}`}
          width={40}
          height={40}
          className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
        />
        <div className="flex flex-col">
          <h3 className="font-semibold">{`${assistant.first_name} ${assistant.last_name}`}</h3>
          <p className="text-xs text-gray-500">ID: {assistant.id}</p>
        </div>
      </td>
      <td className="hidden md:table-cell">{assistant.phone_number}</td>
      <td className="hidden md:table-cell">{assistant.email}</td>
      <td className="hidden lg:table-cell">{assistant.address}</td>
      <td className="hidden md:table-cell">
        <span
          className={`px-2 py-1 rounded-full text-xs font-medium ${
            assistant.status === 'active' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'
          }`}
        >
          {assistant.status}
        </span>
      </td>
      <td>
        <div className="flex items-center gap-2">
          {/* View Button */}
          <Link href={`assistants/${assistant.id}`}>
            <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
              <img src="/view.png" alt="View" width={16} height={16} />
            </button>
          </Link>

          {/* Admin-only actions */}
          {role === "admin" && (
            <>
              {/* Update Assistant */}
              <FormModal table="assistant" type="update" data={assistant} schools={schools} />

              {/* Delete Assistant */}
              <FormModal table="assistant" type="delete" id={assistant.id} route="assistants" />
            </>
          )}
        </div>
      </td>
    </tr>
  );

  return (
    <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
    
      <div className="flex items-center justify-between">
        <h1 className="hidden md:block text-lg font-semibold">All Assistants</h1>
        <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
          <TableSearch routeName="assistants.index"/>
          <div className="flex items-center gap-4 self-end">
           
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/filter.png" alt="Filter" width={14} height={14} />
            </button>
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/sort.png" alt="Sort" width={14} height={14} />
            </button>
            {role === "admin" && (
              <FormModal table="assistant" type="create" schools={schools} />
            )}
          </div>
        </div>
      </div>


      <Table columns={columns} renderRow={renderRow} data={assistants.data} />

      {/* Pagination */}
      <Pagination links={assistants.links} />
    </div>
  );
};

AssistantsListPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default AssistantsListPage;
