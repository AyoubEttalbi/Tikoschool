import FormModal from '@/Components/FormModal';
import TableSearch from '@/Components/TableSearch';
import DashboardLayout from '@/Layouts/DashboardLayout';
import React from 'react'
import { role } from "@/lib/data";
import { Link } from '@inertiajs/react';
export default function ClassesPage() {
    const classes = [
        { id: 1, name: '2BAC SVT G1', level: '2BAC' },
        { id: 2, name: '1BAC PC G2', level: '1BAC' },
        { id: 3, name: '3BAC Math G3', level: '3BAC' },
        { id: 4, name: '2BAC Science G4', level: '2BAC' },
        { id: 5, name: '1BAC English G5', level: '1BAC' },
    ];
    return (
        <div>
            <div className="">
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
                            {role === "admin" && (
                                <FormModal table="class" type="create" />
                            )}
                        </div>
                    </div>
                </div>
                <div className="mt-4">
                    <table className="w-full text-center text-sm">
                        <thead>
                            <tr className="border-b border-gray-200">
                                <th className="px-4 py-2">Name</th>
                                <th className="px-4 py-2">Level</th>
                                <th className="px-4 py-2">Number of Students</th>
                                <th className="px-4 py-2">Number of Teachers</th>
                                <th className="px-4 py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {classes.map((classe) => (
                                <tr key={classe.id} className="border-b border-gray-200">
                                    <td className="px-4 py-2 text-left">{classe.name}</td>
                                    <td className="px-4 py-2">{classe.level}</td>
                                    <td className="px-4 py-2 text-gray-500">22</td>
                                    <td className="px-4 py-2 text-gray-500">2</td>

                                    <td className="px-4 py-2 flex items-center justify-center gap-2">
                                        <Link href={`/classes/${classe.id}`}>
                                            <button className="flex items-center justify-center rounded-full bg-lamaSky">
                                                <img src="/view.png" alt="" width={16} height={16} />
                                            </button>
                                        </Link>
                                        {role === "admin" && (
                                            <FormModal table="class" type="delete" id={classe.id} />
                                        )}

                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
ClassesPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;