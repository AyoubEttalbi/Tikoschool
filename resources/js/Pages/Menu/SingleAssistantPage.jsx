import React, { useState } from 'react';
import Announcements from "@/Pages/Menu/Announcements/Announcements";
import BigCalendar from "@/Components/BigCalender";
import FormModal from "@/Components/FormModal";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Link, usePage, router } from '@inertiajs/react';
import Pagination from '@/Components/Pagination';
import ActivityLogs from '@/Components/ActivityLogs';
import InvoiceModal from '@/Components/InvoiceModal';
import { AlertCircle, AlertTriangle, Calendar, ChevronRight, Clock, DollarSign, Eye, FileText, User } from 'lucide-react';

const SingleAssistantPage = ({ 
  assistant, 
  announcements=[], 
  classes, 
  subjects, 
  schools, 
  logs,
  recentAbsences = [],
  unpaidInvoices = [],
  expiringMemberships = [],
  recentPayments = [],
  totalAbsences = 0,
  totalUnpaidInvoices = 0,
  totalExpiringMemberships = 0,
  totalRecentPayments = 0,
  selectedSchool = null,
  statistics = {
    students_count: 0,
    classes_count: 0
  }
}) => {
  const role = usePage().props.auth.user.role;
  
  // Ensure logs has a default value with data array
  const safeLogs = logs || { data: [], links: [] };
  const [activeTab, setActiveTab] = useState('absences');
  
  // State for invoice modal
  const [isInvoiceModalOpen, setIsInvoiceModalOpen] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState(null);
  
  // Function to open invoice modal
  const openInvoiceModal = (invoice) => {
    setSelectedInvoice(invoice);
    setIsInvoiceModalOpen(true);
  };
  
  // Function to close invoice modal
  const closeInvoiceModal = () => {
    setIsInvoiceModalOpen(false);
    // Clear selected invoice after animation completes
    setTimeout(() => setSelectedInvoice(null), 300);
  };

  // Helper to determine if we need a "See more" button
  const hasMoreItems = (current, total) => {
    return total > current.length;
  };

  // Handle school change
  const handleSchoolChange = () => {
    // Only trigger if the assistant has multiple schools
    if (assistant?.schools?.length > 1) {
      router.get(route('profiles.select'), { force: 1 }, {
        preserveState: true,
        preserveScroll: true,
      });
    }
  };

  return (
    <div className="flex-1 p-4 flex flex-col gap-4 xl:flex-row">
      {/* LEFT */}
      <div className="w-full xl:w-2/3">
        {/* School Selection Banner - only show if assistant has more than one school */}
        {selectedSchool && assistant.schools && assistant.schools.length > 1 && (
          <div className="w-full mb-4 p-3 bg-lamaSkyLight border border-lamaSky/20 rounded-md flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="h-8 w-8 bg-white rounded-full flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-lamaSky" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838l-2.727 1.666 1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z" />
                </svg>
              </div>
              <div>
                <div className="text-xs text-gray-600">Current School</div>
                <span className="font-medium">{selectedSchool.name}</span>
              </div>
            </div>
            <button 
              onClick={handleSchoolChange}
              className="text-sm px-3 py-1 flex items-center gap-1 bg-lamaSky text-white rounded-md hover:bg-lamaSky/90 transition-colors"
            >
              <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7h12m0 0l-4-4m4 4l-4 4m-4 6H4m0 0l4 4m-4-4l4-4" />
              </svg>
              Change School
            </button>
          </div>
        )}

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
                <span className={`px-3 py-1 rounded-full text-xs font-medium ${
                  assistant.status === 'inactive' 
                    ? 'bg-red-100 text-red-800 border border-red-200' 
                    : 'bg-green-100 text-green-800 border border-green-200'
                }`}>
                  {assistant.status === 'inactive' ? 'Inactive' : 'Active'}
                </span>
              </div>
              <p className="text-sm text-gray-500">
                {assistant.bio || "No bio available."}
              </p>
              <div className="flex items-center justify-between gap-2 flex-wrap text-xs font-medium">
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/school.png" alt="" width={14} height={14} />
                  <span>
                    {assistant.schools && assistant.schools.length > 0 
                      ? assistant.schools.map((school) => school.name).join(", ") 
                      : "N/A"}
                  </span>
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
            <InfoCard icon="/singleAttendance.png" label="Students" value={statistics.students_count} />
            <InfoCard icon="/singleBranch.png" label="School" value={selectedSchool ? selectedSchool.name : "N/A"} />
            <InfoCard icon="/singleLesson.png" label="Classes" value={statistics.classes_count} />
            <InfoCard 
              icon={
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="text-lamaSky">
                  <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                  <circle cx="9" cy="7" r="4"></circle>
                  <polyline points="16 11 18 13 22 9"></polyline>
                </svg>
              }
              label="Status" 
              value={
                <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                  assistant.status === 'inactive' 
                    ? 'bg-red-100 text-red-800' 
                    : 'bg-green-100 text-green-800'
                }`}>
                  {assistant.status === 'inactive' ? 'Inactive' : 'Active'}
                </span>
              } 
            />
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
                onClick={() => setActiveTab('memberships')} 
                isActive={activeTab === 'memberships'}
                icon={<Calendar className="w-4 h-4 mr-1" />}
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
                {hasMoreItems(recentAbsences, totalAbsences) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/attendances?filter=recent&school=${selectedSchool ? selectedSchool.id : ''}`}
                      className="flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 justify-center"
                    >
                      See all {totalAbsences} absences from last 7 days <ChevronRight className="ml-1 w-4 h-4" />
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
                      <button 
                        onClick={() => openInvoiceModal(item)}
                        className="text-blue-600 hover:text-blue-800"
                      >
                        <Eye className="w-4 h-4" />
                      </button>
                    )}
                  ]}
                  emptyMessage="No unpaid invoices found"
                />
                {hasMoreItems(unpaidInvoices, totalUnpaidInvoices) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/invoices?filter=unpaid&school=${selectedSchool ? selectedSchool.id : ''}`}
                      className="flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 justify-center"
                    >
                      See all {totalUnpaidInvoices} unpaid invoices <ChevronRight className="ml-1 w-4 h-4" />
                    </Link>
                  </div>
                )}
              </>
            )}
            
            {activeTab === 'memberships' && (
              <>
                <DataTable
                  data={expiringMemberships}
                  columns={[
                    { header: "Student", accessor: (item) => (
                      <Link href={`/students/${item.student_id}`} className="text-blue-600 hover:text-blue-800">
                        {item.student_name}
                      </Link>
                    )},
                    { header: "Started", accessor: (item) => formatDate(item.start_date) },
                    { header: "Expires", accessor: (item) => formatDate(item.end_date) },
                    { header: "Days Left", accessor: (item) => (
                      <span className="font-semibold text-amber-600">{item.days_left}</span>
                    )},
                    { header: "Actions", accessor: (item) => (
                      <Link href={`/memberships/${item.id}`} className="text-blue-600 hover:text-blue-800">
                        <Eye className="w-4 h-4" />
                      </Link>
                    )}
                  ]}
                  emptyMessage="No memberships expiring soon"
                />
                {hasMoreItems(expiringMemberships, totalExpiringMemberships) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/memberships?filter=expiring&school=${selectedSchool ? selectedSchool.id : ''}`}
                      className="flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 justify-center"
                    >
                      See all {totalExpiringMemberships} expiring memberships <ChevronRight className="ml-1 w-4 h-4" />
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
                    { header: "Date", accessor: (item) => formatDate(item.payment_date) },
                    { header: "Amount", accessor: (item) => (
                      <span className="font-semibold text-green-600">{item.amount} DH</span>
                    )},
                    { header: "Method", accessor: "payment_method" },
                    { header: "Offer", accessor: "offer_name" },
                    { header: "Actions", accessor: (item) => (
                      <button 
                        onClick={() => openInvoiceModal(item)}
                        className="text-blue-600 hover:text-blue-800"
                      >
                        <Eye className="w-4 h-4" />
                      </button>
                    )}
                  ]}
                  emptyMessage="No recent payments found"
                />
                {hasMoreItems(recentPayments, totalRecentPayments) && (
                  <div className="mt-4 text-center">
                    <Link 
                      href={`/payments?filter=recent&school=${selectedSchool ? selectedSchool.id : ''}`}
                      className="flex items-center px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800 justify-center"
                    >
                      See all {totalRecentPayments} recent payments <ChevronRight className="ml-1 w-4 h-4" />
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

      {/* RIGHT */}
      <div className="w-full xl:w-1/3 flex flex-col gap-4">
        <div className="bg-white p-4 rounded-md">
          <h2 className="text-lg font-semibold mb-4">Assistant's Calendar</h2>
          <BigCalendar />
        </div>
        <Announcements announcements={announcements} userRole={role} />
      </div>
      
      {/* Invoice Modal */}
      <InvoiceModal 
        isOpen={isInvoiceModalOpen}
        closeModal={closeInvoiceModal}
        invoice={selectedInvoice}
      />
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
    {typeof icon === 'string' ? (
      <img src={icon} alt="" width={24} height={24} className="w-6 h-6" />
    ) : (
      icon
    )}
    <div>
      <div className="text-xl font-semibold">{value}</div>
      <span className="text-sm text-gray-400">{label}</span>
    </div>
  </div>
);

SingleAssistantPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default SingleAssistantPage;