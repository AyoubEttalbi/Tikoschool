import FormModal from '@/Components/FormModal';
import Pagination from '@/Components/Pagination';
import Table from '@/Components/Table';
import TableSearch from '@/Components/TableSearch';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { role } from '@/lib/data';
import React from 'react';
import { Link } from '@inertiajs/react';

export default function SingleClassPage({ className, students }) {
    const columns = [
        { header: "Info", accessor: "info" },
        { header: "Student ID", accessor: "studentId", className: "hidden md:table-cell" },
        { header: "Grade", accessor: "grade", className: "hidden md:table-cell" },
        { header: "Phone", accessor: "phone", className: "hidden lg:table-cell" },
        { header: "Address", accessor: "address", className: "hidden lg:table-cell" },
        { header: "Actions", accessor: "action" },
    ];

    const renderRow = (item) => (
        <tr key={item.studentId} className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight">
            <td className="flex items-center gap-4 p-4">
                <img
                    src={item.photo}
                    alt=""
                    width={40}
                    height={40}
                    className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
                />
                <div className="flex flex-col">
                    <h3 className="font-semibold">{item.name}</h3>
                    <p className="text-xs text-gray-500">{item.class}</p>
                </div>
            </td>
            <td className="hidden md:table-cell">{item.studentId}</td>
            <td className="hidden md:table-cell">{item.grade}</td>
            <td className="hidden md:table-cell">{item.phone}</td>
            <td className="hidden md:table-cell">{item.address}</td>
            <td>
                <div className="flex items-center gap-2">
                    <Link href={`/students/${item.studentId}`}>
                        <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
                            <img src="/view.png" alt="" width={16} height={16} />
                        </button>
                    </Link>
                    {role === "admin" && (
                        <FormModal table="student" type="delete" id={item.studentId} />
                    )}
                </div>
            </td>
        </tr>
    );

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
            {/* TOP */}
            <div className="flex items-center justify-between">
                <h1 className="hidden md:block text-lg font-semibold">Students in {className}</h1>
                <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                    <TableSearch />
                    <div className="flex items-center gap-4 self-end">
                        <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
                            <img src="/filter.png" alt="" width={14} height={14} />
                        </button>
                        <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
                            <img src="/sort.png" alt="" width={14} height={14} />
                        </button>
                        {role === "admin" && (
                            <FormModal table="student" type="create" />
                        )}
                    </div>
                </div>
            </div>

          
            <Table columns={columns} renderRow={renderRow} data={students} />

            <Pagination />
        </div>
    );
}

SingleClassPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
