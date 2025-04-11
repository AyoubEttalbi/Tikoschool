import FormModal from '@/Components/FormModal';
import Pagination from '@/Components/Pagination';
import Table from '@/Components/Table';
import TableSearch from '@/Components/TableSearch';
import StudentPromotionManager from '@/Components/StudentPromotionManager';
import StudentPromotionSetup from '@/Components/StudentPromotionSetup';
import PromotionGuide from '@/Components/PromotionGuide';
import DashboardLayout from '@/Layouts/DashboardLayout';

import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';

export default function SingleClassPage({ className, students, Alllevels, Allclasses, Allschools, teachers, promotionData }) {
    const { auth } = usePage().props;
    const role = auth.user.role;
    const [showPromotionTools, setShowPromotionTools] = useState(false);
    const [showPromotionSetup, setShowPromotionSetup] = useState(false);
    
    console.log("class" , className);
    console.log("students" , students);
    console.log("levels" , Alllevels);
    console.log("classes" , Allclasses);
    
    const columns = [
        { header: "Info", accessor: "info" },
        { header: "Student ID", accessor: "studentId", className: "hidden md:table-cell" },
        { header: "Grade", accessor: "grade", className: "hidden md:table-cell" },
        { header: "Phone", accessor: "phone", className: "hidden lg:table-cell" },
        { header: "Address", accessor: "address", className: "hidden lg:table-cell" },
        { header: "Actions", accessor: "action" },
    ];

    const handlePromotionSetupClose = (success) => {
        setShowPromotionSetup(false);
        // If setup was successful, show the individual promotion tools
        if (success) {
            setShowPromotionTools(true);
        }
    };

    const setupPromotions = () => {
        console.log("Setting up promotions");
        setShowPromotionSetup(true);
    };

    const renderRow = (item) => {
        // Find promotion status for this student
        const promotionStatus = promotionData?.find(p => p.student_id === item.id);
        
        return (
            <tr key={item.id} className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight">
                <td className="flex items-center gap-4 p-4">
                    <img
                        src='/studentProfile.png'
                        alt=""
                        width={40}
                        height={40}
                        className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
                    />
                    <div className="flex flex-col">
                        <h3 className="font-semibold">{item.firstName} {item.lastName}</h3>
                        <p className="text-xs text-gray-500">{Allclasses.find((group) => group.id === item.classId)?.name}</p>
                        
                        {/* Show promotion manager if showPromotionTools is true */}
                        {showPromotionTools && (
                            <div className="mt-2">
                                <StudentPromotionManager 
                                    student={item} 
                                    promotionStatus={promotionStatus} 
                                    isAdmin={role === "admin"} 
                                    isAssistant={role === "assistant"} 
                                />
                            </div>
                        )}
                    </div>
                </td>
                <td className="hidden md:table-cell">{item.massarCode}</td> 
                <td className="hidden md:table-cell">{Alllevels.find((level) => level.id === item.levelId)?.name}</td>
                <td className="hidden md:table-cell">{item.phoneNumber}</td>
                <td className="hidden md:table-cell">{item.address}</td>
                <td>
                    <div className="flex items-center gap-2">
                        <Link href={`/students/${item.id}`}>
                            <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
                            <Eye className="w-4 h-4 text-white"/>
                            </button>
                        </Link>
                        {role === "admin" && (
                            <>
                            <FormModal table="student" type="update" data={item} levels={Alllevels} classes={Allclasses} schools={Allschools} />
                            <FormModal table="student" type="delete" id={item.id} route="students"/>
                            </>
                        )}
                    </div>
                </td>
            </tr>
        );
    };

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
            {/* TOP */}
            <div className="flex items-center justify-between mb-4">
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
                            <FormModal table="student" type="create" levels={Alllevels} classes={Allclasses} schools={Allschools}/>
                        )}
                    </div>
                </div>
            </div>
            
            {/* Promotion Tools */}
            {(role === "admin" || role === "assistant") && (
                <PromotionGuide 
                    onSetupClick={setupPromotions}
                    showPromotionTools={showPromotionTools}
                />
            )}

            {/* Promotion Setup Modal */}
            <StudentPromotionSetup 
                students={students}
                className={className}
                levels={Alllevels}
                promotionData={promotionData}
                isOpen={showPromotionSetup}
                onClose={handlePromotionSetupClose}
            />

            <Table columns={columns} renderRow={renderRow} data={students} />

            <Pagination />
        </div>
    );
}

SingleClassPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
