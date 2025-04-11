import React, { useState } from 'react';
import Announcements from "@/Pages/Menu/Announcements/Announcements";
import BigCalendar from "@/Components/BigCalender";
import FormModal from "@/Components/FormModal";
import Performance from "@/Components/Performance";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Link, usePage, router } from '@inertiajs/react';
import Pagination from '@/Components/Pagination';
import ActivityLogs from '@/Components/ActivityLogs';
import InvoiceModal from '@/Components/InvoiceModal';
import { AlertCircle, AlertTriangle, Calendar, ChevronRight, Clock, DollarSign, Eye, FileText, User } from 'lucide-react';
import AssistantProfile from '@/Components/AssistantProfile';

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
  },
  auth,
  userRole,
  filters,
  recurringTransactions
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
    router.visit('/select-profile');
  };

  return (
    <DashboardLayout title={assistant.first_name + " " + assistant.last_name} auth={auth} userRole={userRole} selectedSchool={selectedSchool}>
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6 bg-white border-b border-gray-200">
              <h1 className="text-2xl font-semibold text-gray-800 mb-6">{assistant.first_name + " " + assistant.last_name}</h1>
              
              {/* Profile Section */}
              <AssistantProfile assistant={assistant} recurringTransactions={recurringTransactions} />

              {/* Tabs */}
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
          </div>
        </div>
      </div>
      
      {/* Invoice Modal */}
      <InvoiceModal 
        isOpen={isInvoiceModalOpen}
        closeModal={closeInvoiceModal}
        invoice={selectedInvoice}
      />
    </DashboardLayout>
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

export default SingleAssistantPage;