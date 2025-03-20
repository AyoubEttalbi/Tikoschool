import { Link ,router,usePage} from '@inertiajs/react';
import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import FormModal from "../../Components/FormModal";
import Register from "../Auth/Register";
import { useState } from "react";
import { useParams } from 'react-router-dom';
import { Edit, Eye, Plus, PlusIcon } from 'lucide-react';
import UpdateUser from '../Auth/UpdateUser';
// Define table columns for users
const columns = [
  {
    header: "Name",
    accessor: "name",
  },
  {
    header: "Email",
    accessor: "email",
    className: "hidden md:table-cell",
  },
  {
    header: "Role",
    accessor: "role",
    className: "hidden md:table-cell",
  },
  {
    header: "Created At",
    accessor: "created_at",
    className: "hidden md:table-cell",
  },
  {
    header: "Actions",
    accessor: "actions",
  },
];

const UserListPage = ({users}) => {
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isUpdateOpen, setIsUpdateOpen] = useState({
    isOpen: false,
    id: null,
  });
   const role=usePage().props.auth.user.role;
   const { url } = usePage();

  const data = new URLSearchParams(url.split('?')[1]).get('data');

  const parsedData = JSON.parse(decodeURIComponent(data));

  // Now you can access the data

  const handleViewAs = (userId) => {
    router.post(`/admin/view-as/${userId}`, {}, {
      onSuccess: () => {
        // Redirect to the dashboard after successfully switching accounts
        router.visit('/dashboard');
      },
      onError: (errors) => {
        console.error('Error viewing as user:', errors);
      },
    });
  };
  // Format the created_at date to YYYY-MM-DD
  const formatDate = (dateString) => {
    return new Date(dateString).toISOString().split("T")[0];
  };

  // Render each row of the table
  const renderRow = (user) => (
    <tr
      key={user.id}
      className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight"
    > 
    
      <td className="flex items-center gap-4 p-4">
      <img
          src="/avatar.png"
          alt={user.name}
          width={40}
          height={40}
          className="hidden md:block xl:block w-10 h-10 rounded-full object-cover"
        />
        <div className="flex flex-col">
          <h3 className="font-semibold">{user.name}</h3>
          <p className="text-xs text-gray-500 lg:hidden md:block">{user.email}</p>
        </div>
      </td>
      <td className="p-4 hidden md:table-cell">{user.email}</td>
      <td className="hidden md:table-cell">
        <span
          className={`px-2 py-1 rounded-full text-xs font-medium ${
            user.role === "admin"
              ? "bg-blue-100 text-blue-600"
              : user.role === "assistant"
              ? "bg-green-100 text-green-600"
              : "bg-gray-100 text-gray-600"
          }`}
        >
          {user.role}
        </span>
      </td>
      <td className="hidden md:table-cell p-4">{formatDate(user.created_at)}</td>
      <td className="p-4">
        <div className="flex items-center gap-2">
         
         

          {/* Admin-only actions */}
          {role === "admin" && (
            <>
              <button
                disabled={user.role === "admin"}
                onClick={() => handleViewAs(user.id)}
                className={`p-2 bg-blue-500 ${user.role === "admin" ? "opacity-50 cursor-not-allowed" : ""} text-white rounded-full hover:bg-blue-600 transition duration-200`}
              >
                <Eye className="w-4 h-4 text-white" />
              </button>
              {/* Update User */}
              <button
                onClick={() => setIsUpdateOpen({...isUpdateOpen,isOpen: true, id: user.id})}
                className="p-2 bg-lamaYellow  text-black rounded-full hover:bg-yellow-500 transition duration-200"
            >
               <Edit className="w-4 h-4 text-white" />
            </button>
        
        
              {/* Delete User */}
              <FormModal table="user"  type="delete" id={user.id} />
            </>
          )}
        </div>
      </td>
    </tr>
  );

  return (
  
    <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
      <div className="flex items-center justify-between">
        <h1 className="hidden md:block text-lg font-semibold">All Users</h1>
        <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
          <TableSearch />
          <div className="flex items-center gap-4 self-end">
            {/* Filter and Sort Buttons */}
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/filter.png" alt="Filter" width={14} height={14} />
            </button>
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/sort.png" alt="Sort" width={14} height={14} />
            </button>

            {/* Admin-only Create User Button */}
            <button
                onClick={() => setIsModalOpen(true)}
                className="p-2 bg-lamaYellow  text-black rounded-full hover:bg-yellow-500 transition duration-200"
            >
               <PlusIcon className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
      {
        isModalOpen && <Register isModalOpen={isModalOpen} table={"users"} setIsModalOpen={setIsModalOpen}/> 
      }
          {
        isUpdateOpen && <UpdateUser isUpdateOpen={isUpdateOpen} userData={users} type={"update"} setIsUpdateOpen={setIsUpdateOpen}/> 
      }
      {/* Table */}
      <Table columns={columns} renderRow={renderRow} data={users} />

      {/* Pagination */}
      {/* <Pagination links={users.links} /> */}
    </div>

  );
};

UserListPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default UserListPage;