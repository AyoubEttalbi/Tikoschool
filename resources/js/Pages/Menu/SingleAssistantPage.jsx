import React, { useState } from 'react';
import Announcements from "@/Pages/Menu/Announcements/Announcements";
import BigCalendar from "@/Components/BigCalender";
import FormModal from "@/Components/FormModal";
import Performance from "@/Components/Performance";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Link, usePage } from '@inertiajs/react';
import Pagination from '@/Components/Pagination';
import ActivityLogs from '@/Components/ActivityLogs';
import { AlertCircle, AlertTriangle, Calendar, ChevronRight, Clock, DollarSign, Eye, FileText, User } from 'lucide-react';

const SingleAssistantPage = ({ 
  assistant, 
  announcements=[], 
  classes, 
  subjects, 
  schools, 
  logs,
  metrics = {
    totalStudents: 0,
    totalClasses: 0,
    totalSubjects: 0,
    totalActiveMemberships: 0
  },
  recentAbsences = [],
  unpaidInvoices = [],
  expiringMemberships = [],
  recentPayments = [],
  totalCounts = {
    absences: 0,
    unpaidInvoices: 0,
    expiringMemberships: 0,
    recentPayments: 0
  }
}) => {
  const role = usePage().props.auth.user.role;
  
  // Ensure logs has a default value with data array
  const safeLogs = logs || { data: [], links: [] };
  const [activeTab, setActiveTab] = useState('absences');

  // Helper to determine if we need a "See more" button
  const hasMoreItems = (current, total) => {
    return total > current.length;
  };

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
                src={assistant.profile_image || "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg?auto=compress&cs=tinysrgb&w=1200"}
                alt={assistant.last_name}
                width={144}
                height={144}
                className="w-36 h-36 rounded-full object-cover"
              />
            </div>
            <div className="w-2/3 flex flex-col justify-between gap-4">
              <div className="flex items-center gap-4">
                <h1 className="text-xl font-semibold">{assistant.first_name} {assistant.last_name}</h1>
              </div>
              <p className="text-sm text-gray-500">
                {assistant.bio || "No bio available."}
              </p>
              <div className="flex items-center justify-between gap-2 flex-wrap text-xs font-medium">
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/school.png" alt="" width={14} height={14} />
                  <span>{assistant.schools_assistant.map((school) => school.name).join(", ") || "N/A"}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/date.png" alt="" width={14} height={14} />
                  <span>
                    {assistant.created_at ?
                      new Intl.DateTimeFormat('en-GB', {
                        month: 'long',
                        year: 'numeric'
                      }).format(new Date(assistant.created_at)) :
                      "N/A"
                    }
                  </span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/mail.png" alt="" width={14} height={14} />
                  <span>{assistant.email}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/phone.png" alt="" width={14} height={14} />
                  <span>{assistant.phone_number}</span>
                </div>
              </div>
            </div>
            {role === "admin" && (
              <FormModal
                table="assistant"
                type="update"
                data={assistant}
                schools={schools}
                groups={classes}
                subjects={subjects}
                icon={'updateIcon2'}
              />
            )}
          </div>

          {/* SMALL CARDS */}
          <div className="flex-1 flex gap-4 justify-between flex-wrap">
            <InfoCard icon="/singleAttendance.png" label="Students" value={metrics.totalStudents} />
            <InfoCard icon="/singleBranch.png" label="Subjects" value={metrics.totalSubjects} />
            <InfoCard icon="/singleLesson.png" label="Memberships" value={metrics.totalActiveMemberships} />
            <InfoCard icon="/singleClass.png" label="Classes" value={metrics.totalClasses} />
          </div>
        </div>

        {/* FEATURE TABS */}
        <div className="mt-4 bg-white rounded-md p-4">
          <div className="border-b border-gray-200 mb-4">
            <nav className="-mb-px flex overflow-x-auto pb-1">
              <TabButton 
                onClick={() => setActiveTab('absences')} 
                isActive={activeTab === 'absences'}
                icon={<AlertCircle className="w-4 h-4 mr-1" />}
                text="Recent Absences"
              />
              <TabButton 
                onClick={() => setActiveTab('unpaid')} 
                isActive={activeTab === 'unpaid'}
                icon={<FileText className="w-4 h-4 mr-1" />}
                text="Unpaid Invoices"
              />
              <TabButton 
                onClick={() => setActiveTab('expiring')} 
                isActive={activeTab === 'expiring'}
                icon={<Clock className="w-4 h-4 mr-1" />}
                text="Expiring Memberships"
              />
              <TabButton 
                onClick={() => setActiveTab('payments')} 
                isActive={activeTab === 'payments'}
                icon={<DollarSign className="w-4 h-4 mr-1" />}
                text="Recent Payments"
              />
            </nav>
          </div>

          {/* Tab Content */}
          <div className="py-2">
            {activeTab === 'absences' && (
              <>
                <DataTable
                  data={recentAbsences}
                  columns={[
                    { header: "Student", accessor: (item) => (
                      <Link href={`/students/${item.student_id}`} className="text-blue-600 hover:text-blue-800">
                        {item.student_name}
                      </Link>
                    )},
                    { header: "Class", accessor: "class_name" },
                    { header: "Date", accessor: (item) => formatDate(item.date) },
                    { header: "Status", accessor: (item) => (
                      <span className={`px-2 py-1 rounded-full text-xs ${item.status === 'absent' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'}`}>
                        {item.status}
                      </span>
                    )},
                    { header: "Reason", accessor: "reason" }
                  ]}
                  emptyMessage="No recent absences in the last 30 days"
                />
                {hasMoreItems(recentAbsences, totalCounts.absences) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/attendances?filter=recent&school=${assistant.schools_assistant.map(s => s.id).join(',')}`}
                      className="inline-flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                    >
                      See all {totalCounts.absences} absences from last 7 days <ChevronRight className="ml-1 w-4 h-4" />
                    </Link>
                  </div>
                )}
              </>
            )}

            {activeTab === 'unpaid' && (
              <>
                <DataTable
                  data={unpaidInvoices}
                  columns={[
                    { header: "Student", accessor: (item) => (
                      <Link href={`/students/${item.student_id}`} className="text-blue-600 hover:text-blue-800">
                        {item.student_name}
                      </Link>
                    )},
                    { header: "Bill Date", accessor: (item) => formatDate(item.bill_date) },
                    { header: "Total", accessor: (item) => `${item.total_amount} DH` },
                    { header: "Remaining", accessor: (item) => (
                      <span className="font-semibold text-red-600">{item.rest} DH</span>
                    )},
                    { header: "Actions", accessor: (item) => (
                      <Link href={`/invoices/${item.id}`} className="text-blue-600 hover:text-blue-800">
                        <Eye className="w-4 h-4" />
                      </Link>
                    )}
                  ]}
                  emptyMessage="No unpaid invoices found"
                />
                {hasMoreItems(unpaidInvoices, totalCounts.unpaidInvoices) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/invoices?filter=unpaid&school=${assistant.schools_assistant.map(s => s.id).join(',')}`}
                      className="inline-flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                    >
                      See all {totalCounts.unpaidInvoices} unpaid invoices <ChevronRight className="ml-1 w-4 h-4" />
                    </Link>
                  </div>
                )}
              </>
            )}

            {activeTab === 'expiring' && (
              <>
                <DataTable
                  data={expiringMemberships}
                  columns={[
                    { header: "Student", accessor: (item) => (
                      <Link href={`/students/${item.student_id}`} className="text-blue-600 hover:text-blue-800">
                        {item.student_name}
                      </Link>
                    )},
                    { header: "Offer", accessor: "offer_name" },
                    { header: "Expires", accessor: (item) => formatDate(item.end_date) },
                    { header: "Days Left", accessor: (item) => (
                      <span className={`px-2 py-1 rounded-full text-xs ${item.days_remaining <= 3 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'}`}>
                        {item.days_remaining} days
                      </span>
                    )}
                  ]}
                  emptyMessage="No memberships expiring soon"
                />
                {hasMoreItems(expiringMemberships, totalCounts.expiringMemberships) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/memberships?filter=expiring&school=${assistant.schools_assistant.map(s => s.id).join(',')}`}
                      className="inline-flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                    >
                      See all {totalCounts.expiringMemberships} memberships expiring soon <ChevronRight className="ml-1 w-4 h-4" />
                    </Link>
                  </div>
                )}
              </>
            )}

            {activeTab === 'payments' && (
              <>
                <DataTable
                  data={recentPayments}
                  columns={[
                    { header: "Student", accessor: (item) => (
                      <Link href={`/students/${item.student_id}`} className="text-blue-600 hover:text-blue-800">
                        {item.student_name}
                      </Link>
                    )},
                    { header: "Amount", accessor: (item) => `${item.amount_paid} DH` },
                    { header: "Date", accessor: (item) => formatDate(item.payment_date) },
                    { header: "Actions", accessor: (item) => (
                      <Link href={`/invoices/${item.id}`} className="text-blue-600 hover:text-blue-800">
                        <Eye className="w-4 h-4" />
                      </Link>
                    )}
                  ]}
                  emptyMessage="No recent payments found"
                />
                {hasMoreItems(recentPayments, totalCounts.recentPayments) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/invoices?filter=recent_payments&school=${assistant.schools_assistant.map(s => s.id).join(',')}`}
                      className="inline-flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                    >
                      See all {totalCounts.recentPayments} recent payments <ChevronRight className="ml-1 w-4 h-4" />
                    </Link>
                  </div>
                )}
              </>
            )}
          </div>
            </div>

        {/* ACTIVITY LOGS */}
        <div className="flex flex-col gap-4 mt-4">
          
            <ActivityLogs logs={safeLogs} />
          
        </div>
    </div>

      {/* RIGHT */ }
  <div className="w-full xl:w-1/3 flex flex-col gap-4">
        <div className="bg-white p-4 rounded-md">
          <h2 className="text-lg font-semibold mb-4">Assistant's Calendar</h2>
          <BigCalendar />
        </div>
    <Announcements announcements={announcements} userRole={role} />
  </div>
    </div>
  );
};

// Helper to format dates
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  return new Date(dateString).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  });
};

// Tab Button Component
const TabButton = ({ onClick, isActive, icon, text }) => (
  <button
    onClick={onClick}
    className={`flex items-center px-4 py-2 text-sm font-medium whitespace-nowrap ${
      isActive
        ? 'border-b-2 border-blue-500 text-blue-600'
        : 'text-gray-500 hover:text-gray-700 hover:border-gray-300'
    }`}
  >
    {icon}
    {text}
  </button>
);

// Generic Data Table Component
const DataTable = ({ data, columns, emptyMessage }) => (
  <div className="rounded-lg border border-gray-100 overflow-hidden">
    {data.length === 0 ? (
      <div className="text-center py-8 text-gray-500">
        <AlertTriangle className="w-8 h-8 mx-auto mb-2 text-gray-400" />
        <p>{emptyMessage || "No data available"}</p>
      </div>
    ) : (
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              {columns.map((col, idx) => (
                <th key={idx} className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {col.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {data.map((item, idx) => (
              <tr key={idx} className="hover:bg-gray-50">
                {columns.map((col, colIdx) => (
                  <td key={colIdx} className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {typeof col.accessor === 'function' 
                      ? col.accessor(item) 
                      : item[col.accessor] || 'N/A'}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )}
  </div>
);

const InfoCard = ({ icon, label, value }) => (
  <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
    <img src={icon} alt="" width={24} height={24} className="w-6 h-6" />
    <div>
      <h1 className="text-xl font-semibold">{value}</h1>
      <span className="text-sm text-gray-400">{label}</span>
    </div>
  </div>
);

SingleAssistantPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default SingleAssistantPage;