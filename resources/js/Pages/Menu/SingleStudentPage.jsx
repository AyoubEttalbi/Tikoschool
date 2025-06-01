import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Announcements from "@/Pages/Menu/Announcements/Announcements";
import BigCalendar from "@/Components/BigCalender";

import DashboardLayout from '@/Layouts/DashboardLayout';
import FormModal from '@/Components/FormModal';
import MembershipCard from '@/Components/MembershipCard';
import StudentProfile from '@/Components/StudentProfile';
import StudentPromotionStatus from '@/Components/StudentPromotionStatus';
import { ChevronDown, ChevronUp, Users, AlertCircle, Printer } from 'lucide-react';
import Performance from '@/Components/Performance';
// import studentProfile from "./studentProfile.png";
const SingleStudentPage = ({ student, Alllevels, Allclasses, Allschools, Alloffers, Allteachers,memberships }) => {
  const role = usePage().props.auth.user.role;
  const [showMoreInfo, setShowMoreInfo] = useState(false);
  
  console.log("xxxxx", student);
  console.log("route", Alllevels);
  console.log('Alloffers', Alloffers);
  console.log('Allteachers', Allteachers);
  console.log('memberships', memberships);
  
  console.log("Full student data being passed to Performance:", {
    id: student.id,
    name: student.name,
    attendances: student.attendances,
    results: student.results,
    memberships: student.memberships,
    invoices: student.invoices
  });
 
  return (
    <div className="flex-1 p-4 flex flex-col gap-4 xl:flex-row">
      {/* LEFT */}
      <div className="w-full xl:w-2/3">
        {/* TOP */}
        <div className="flex flex-col lg:flex-row gap-4">
          {/* USER INFO CARD */}
<div className="bg-lamaSky py-6 px-4 rounded-md flex-1 flex gap-4">
  <div className="w-1/3">
    <img
      src={student.profile_image ? student.profile_image : "/studentProfile.png"}
      alt={student.name}
      width={144}
      height={144}
      className="w-36 h-36 rounded-full object-cover"
    />
  </div>
  
  <div className="w-2/3 flex flex-col justify-between gap-4">
    {/* Action Buttons Row */}
    <div className="flex justify-end items-center gap-3">
      {/* Print/PDF Button - Enhanced */}
      <a
        href={route('students.downloadPdf', { student: student.id })}
        className="inline-flex items-center gap-2   text-black rounded-lg shadow-sm  hover:shadow-md transition-all duration-200 font-medium text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 group"
        target="_blank"
        rel="noopener noreferrer"
        download
        title="Download student profile as PDF"
      >
        <Printer className="w-5 h-5 group-hover:text-blue-600 transition-colors" />
    
      </a>

      {/* Edit Button - Enhanced (only show for admin/assistant) */}
      {(role === "admin" || role === "assistant") && (
        <div className="relative">
          <FormModal 
            table="student" 
            type="update" 
            icon={'updateIcon2'} 
            data={student} 
            id={student.id} 
            levels={Alllevels} 
            classes={Allclasses} 
            schools={Allschools}
            className="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-sm hover:shadow-md transition-all duration-200 font-medium text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 group"
            buttonContent={
              <>
                <svg 
                  className="w-4 h-4 group-hover:scale-110 transition-transform duration-150" 
                  fill="none" 
                  stroke="currentColor" 
                  viewBox="0 0 24 24"
                >
                  <path 
                    strokeLinecap="round" 
                    strokeLinejoin="round" 
                    strokeWidth={2} 
                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" 
                  />
                </svg>
                <span className="hidden sm:inline">Edit Profile</span>
                <span className="sm:hidden">Edit</span>
              </>
            }
            title="Edit student information"
          />
        </div>
      )}
    </div>

    {/* Student Name and Status */}
    <div className="flex items-center gap-4">
      <h1 className="text-xl font-semibold">{student.firstName} {student.lastName}</h1>
      <span className={`px-3 py-1 rounded-full text-xs font-medium ${
        student.status === 'inactive'
          ? 'bg-red-100 text-red-800 border border-red-200'
          : 'bg-green-100 text-green-800 border border-green-200'
      }`}>
        {student.status === 'inactive' ? 'Inactive' : 'Active'}
      </span>
    </div>

    {/* Bio */}
    <p className="text-sm text-gray-500">
      {student.bio || "No bio available."}
    </p>

    {/* Student Details */}
    <div className="flex items-center justify-between gap-2 flex-wrap text-xs font-medium">
      <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
        <img src="/school.png" alt="school" width={14} height={14} />
        <span>{Allschools.find(school => school.id === student.schoolId)?.name || ""}</span>
      </div>
      <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
        <img src="/date.png" alt="Date" width={14} height={14} />
        <span>
          {student.created_at ?
            new Intl.DateTimeFormat('en-GB', {
              day: '2-digit',
              month: 'long',
              year: 'numeric'
            }).format(new Date(student.created_at)) :
            "03 January 2025"
          }
        </span>
      </div>
      <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
        <img src="/mail.png" alt="Email" width={14} height={14} />
        <span>{student.email || "user@gmail.com"}</span>
      </div>
      <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
        <img src="/phone.png" alt="Phone" width={14} height={14} />
        <span>{student.phoneNumber || "+1 234 567"}</span>
      </div>
    </div>
  </div>
</div>
          {/* SMALL CARDS */}
          <div className="flex-1 flex gap-4 justify-between flex-wrap">
            {/* CARD */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleAttendance.png"
                alt="Attendance"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{student.attendance || "99%"}</h1>
                <span className="text-sm text-gray-400">Attendance</span>
              </div>
            </div>
            {/* CARD */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleBranch.png"
                alt="Grade"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{Alllevels.find(level => level.id === student.levelId)?.name || ""}</h1>
                <span className="text-sm text-gray-400">Level</span>
              </div>
            </div>
            {/* Status CARD */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="w-6 h-6 text-lamaSky">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <polyline points="16 11 18 13 22 9"></polyline>
              </svg>
              <div className="">
                <div className="text-xl font-semibold">
                  <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                    student.status === 'inactive' 
                      ? 'bg-red-100 text-red-800 border border-red-200' 
                      : 'bg-green-100 text-green-800 border border-green-200'
                  }`}>
                    {student.status === 'inactive' ? 'Inactive' : 'Active'}
                  </span>
                </div>
                <span className="text-sm text-gray-400">Status</span>
              </div>
            </div>
            
            {/* CARD - Offers */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleLesson.png"
                alt="Offer"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{student.memberships.length || "0"}</h1>
                <span className="text-sm text-gray-400">Offers</span>
              </div>
            </div>
            
            {/* CARD - Class */}
            <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
              <img
                src="/singleClass.png"
                alt="Class"
                width={24}
                height={24}
                className="w-6 h-6"
              />
              <div className="">
                <h1 className="text-xl font-semibold">{Allclasses.find(classs => classs.id === student.classId)?.name || "6A"}</h1>
                <span className="text-sm text-gray-400">Class</span>
              </div>
            </div>
          </div>
        </div>
        
        {/* Promotion Status */}
        {(role === "admin" || role === "assistant") && (
          <div className="mt-4">
            <StudentPromotionStatus promotionStatus={student.promotion} />
          </div>
        )}
        
        {/* Additional Student Information */}
        <div className="mt-2">
          <button 
            onClick={() => setShowMoreInfo(!showMoreInfo)}
            className="flex items-center justify-center w-full py-2 px-4 bg-lamaSky text-white rounded-md text-sm font-medium hover:bg-lamaSky/90 transition-colors"
          >
            {showMoreInfo ? (
              <>
                Show Less <ChevronUp className="ml-1 w-4 h-4" />
              </>
            ) : (
              <>
                See More <ChevronDown className="ml-1 w-4 h-4" />
              </>
            )}
          </button>
          
          {showMoreInfo && (
            <div className="mt-4 bg-white rounded-md shadow-md border border-gray-100 animate-fadeIn overflow-hidden">
              <div className="bg-lamaSky/10 px-6 py-3 border-b border-gray-100">
                <h3 className="text-lg font-medium text-gray-800">Additional Student Information</h3>
              </div>
              
              <div className="p-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div className="bg-lamaSkyLight/30 p-4 rounded-lg">
                    <h4 className="text-lamaSky font-medium mb-3 flex items-center">
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                      </svg>
                      Personal Information
                    </h4>
                    <div className="space-y-3">
                      <div className="flex justify-between border-b border-blue-100 pb-2">
                        <div className="text-gray-600 text-sm">Date of Birth</div>
                        <div className="font-medium text-sm">{student.dateOfBirth ? new Date(student.dateOfBirth).toLocaleDateString() : "N/A"}</div>
                      </div>
                      <div className="flex justify-between border-b border-blue-100 pb-2">
                        <div className="text-gray-600 text-sm">Guardian Number</div>
                        <div className="font-medium text-sm">{student.guardianNumber || "N/A"}</div>
                      </div>
                      <div className="flex justify-between border-b border-blue-100 pb-2">
                        <div className="text-gray-600 text-sm">CIN</div>
                        <div className="font-medium text-sm">{student.CIN || "N/A"}</div>
                      </div>
                      <div className="flex justify-between">
                        <div className="text-gray-600 text-sm">Address</div>
                        <div className="font-medium text-sm text-right">{student.address || "N/A"}</div>
                      </div>
                    </div>
                  </div>
                  
                  <div className="bg-lamaPurpleLight/30 p-4 rounded-lg">
                    <h4 className="text-lamaPurple font-medium mb-3 flex items-center">
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path d="M12 14l9-5-9-5-9 5 9 5z" />
                        <path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" />
                      </svg>
                      Academic Information
                    </h4>
                    <div className="space-y-3">
                      <div className="flex justify-between border-b border-purple-100 pb-2">
                        <div className="text-gray-600 text-sm">Massar Code</div>
                        <div className="font-medium text-sm">{student.massarCode || "N/A"}</div>
                      </div>
                      <div className="flex justify-between border-b border-purple-100 pb-2">
                        <div className="text-gray-600 text-sm">Billing Date</div>
                        <div className="font-medium text-sm">{student.billingDate ? new Date(student.billingDate).toLocaleDateString() : "N/A"}</div>
                      </div>
                      <div className="flex justify-between">
                        <div className="text-gray-600 text-sm">Status</div>
                        <div className="font-medium text-sm">
                          <span className={`px-2 py-1 rounded-full text-xs ${
                            student.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                          }`}>
                            {student.status || "Active"}
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <div className="bg-amber-50 p-4 rounded-lg md:col-span-2">
                    <h4 className="text-amber-600 font-medium mb-3 flex items-center">
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      Health Information
                    </h4>
                    
                    {student.hasDisease ? (
                      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-white p-3 rounded-md border border-amber-100">
                          <div className="text-amber-700 text-xs mb-1">Medical Condition</div>
                          <div className="font-medium text-gray-800">{student.diseaseName || "N/A"}</div>
                        </div>
                        <div className="bg-white p-3 rounded-md border border-amber-100">
                          <div className="text-amber-700 text-xs mb-1">Medication</div>
                          <div className="font-medium text-gray-800">{student.medication || "N/A"}</div>
                        </div>
                        <div className="bg-white p-3 rounded-md border border-amber-100">
                          <div className="text-amber-700 text-xs mb-1">Insurance</div>
                          <div className="font-medium text-gray-800">{student.assurance ? "Yes" : "No"}</div>
                        </div>
                      </div>
                    ) : (
                      <div className="bg-white p-4 rounded-md border border-amber-100 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-3 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span className="text-gray-700 font-medium">Student is in good health with no reported medical conditions.</span>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
        
        {/* BOTTOM */}
        <div className="mt-4 bg-white rounded-md p-4 h-fit flex flex-col justify-center items-start">
        <div className="w-full max-w-lg p-6 bg-white rounded-lg shadow-md border border-gray-200">
          <div className="flex justify-between items-center mb-6">
            <div className="flex items-center gap-2 text-blue-600 font-medium mr-4">
              <span className='flex flex-row'>
                <Users className="h-5 w-5 mr-3" />
                Memberships:
                {/* Display badge with number of unpaid memberships */}
                {student.memberships && student.memberships.filter(m => m.payment_status !== "paid").length > 0 && (
                  <span className="ml-2 bg-amber-100 text-amber-800 text-xs font-medium px-2 py-0.5 rounded-full">
                    {student.memberships.filter(m => m.payment_status !== "paid").length} unpaid
                  </span>
                )}
              </span>
            </div>
            <div className="flex items-center gap-2">
              
              <FormModal table="membership" type="create" id={student.id} offers={Alloffers} teachers={Allteachers} studentId={student.id} />
            </div>
          </div>
          {
            student.memberships ? (
              <>
                {student.memberships.filter(m => m.payment_status !== "paid").length > 0 && (
                  <div className="mb-4 p-3 bg-amber-50 border-l-4 border-amber-500 rounded-md">
                    <div className="flex items-start">
                      <AlertCircle className="h-5 w-5 text-amber-500 mt-0.5 mr-2" />
                      <div>
                        <h3 className="text-sm font-medium text-amber-800">Unpaid Memberships</h3>
                        <p className="text-sm text-amber-700 mt-1">
                          This student has {student.memberships.filter(m => m.payment_status !== "paid").length} unpaid {student.memberships.filter(m => m.payment_status !== "paid").length === 1 ? 'membership' : 'memberships'}.
                          Add an invoice to complete the payment process.
                        </p>
                      </div>
                    </div>
                  </div>
                )}
                <MembershipCard Student_memberships={student.memberships} teachers={Allteachers} offers={Alloffers} studentId={student.id}/>
              </>
            ) : (
              <div className="flex flex-col items-center gap-4">
                <img src="/student.png" alt="Student" width={64} height={64} className="mb-4" />
                <h1 className="text-2xl font-semibold text-gray-700">No Membership</h1>
              </div>
            )
          }
        </div>
        </div>
        
        {/* Student Profile with grades, invoices, and attendance */}
        <StudentProfile 
          Student_memberships={student.memberships} 
          invoices={student.invoices} 
          studentId={student.id} 
          studentClassId={student.classId}
          attendances={student.attendances}
          results={student.results}
        />

      </div>
      {/* RIGHT */}
      <div className="w-full xl:w-1/3 flex flex-col gap-4">
        <div className="bg-white p-4 rounded-md">
          <h1 className="text-xl font-semibold">Shortcuts</h1>
          <div className="mt-4 flex gap-4 flex-wrap text-xs text-gray-500">
            {/* <Link className="p-3 rounded-md bg-lamaSkyLight" href="/lessons">
              Student&apos;s Lessons
            </Link> */}
            <Link className="p-3 rounded-md bg-lamaPurpleLight" href="/teachers">
              Student&apos;s Teachers
            </Link>
            <Link className="p-3 rounded-md bg-lamaSkyLight" href="/attendances">
              Student&apos;s Attendances
            </Link>
            <Link className="p-3 rounded-md bg-pink-50" href="/classes">
              Student&apos;s Classes
            </Link>
            {/* <Link className="p-3 rounded-md bg-pink-50" href="/exams">
              Student&apos;s Exams
            </Link> */}
              {/* <Link className="p-3 rounded-md bg-lamaSkyLight" href="/assignments">
                Student&apos;s Assignments
              </Link> */}
            <Link className="p-3 rounded-md bg-lamaYellowLight" href="/results">
              Student&apos;s Results
            </Link>
          </div>
        </div>
        <Performance student={student} />
        <Announcements />
      </div>

    </div>
  );
};
SingleStudentPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default SingleStudentPage;