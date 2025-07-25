import React, { useState, useMemo } from 'react';
import Table from "@/Components/Table";
import Pagination from "@/Components/Pagination";
import { Eye, Download, Search, Calendar, FileText, ChevronDown, X, Filter, ArrowUpDown } from "lucide-react";
import { Link, router } from '@inertiajs/react';

const TeacherInvoicesTable = ({ invoices=[], invoiceslinks=[] }) => {
    console.log('TeacherInvoicesTable received invoices:', invoices);
    console.log('TeacherInvoicesTable received links:', invoiceslinks);
    
    // Ensure invoices is an array even if undefined is passed
    const safeInvoices = Array.isArray(invoices) ? invoices : [];
    
    // Filters State
    const [search, setSearch] = useState("");
    const [classFilter, setClassFilter] = useState("all");
    const [offerFilter, setOfferFilter] = useState("all");
    const [schoolFilter, setSchoolFilter] = useState("all");
    const [dateFilter, setDateFilter] = useState("");
    const [sortField, setSortField] = useState("billDate");
    const [sortDirection, setSortDirection] = useState("desc");
    const [filtersVisible, setFiltersVisible] = useState(false);
    const [selectedInvoices, setSelectedInvoices] = useState([]);

    // Get unique classes, offers, and schools for dropdowns
    const uniqueClasses = [...new Set(safeInvoices.map(inv => inv.student_class))];
    const uniqueOffers = [...new Set(safeInvoices.map(inv => inv.offer_name))];
    const uniqueSchools = [...new Set(safeInvoices.map(inv => inv.student_school))];

    // Handle sort change
    const handleSort = (field) => {
        if (sortField === field) {
            setSortDirection(sortDirection === "asc" ? "desc" : "asc");
        } else {
            setSortField(field);
            setSortDirection("asc");
        }
    };

    // Filter and sort invoices
    const filteredInvoices = useMemo(() => {
        let filtered = safeInvoices.filter(invoice => {
            // Skip invalid invoices
            if (!invoice || typeof invoice !== 'object') return false;
            
            // Handle potentially missing properties safely
            const studentName = invoice.student_name || '';
            const studentClass = invoice.student_class || '';
            const studentSchool = invoice.student_school || '';
            const offerName = invoice.offer_name || '';
            const billDate = invoice.billDate || '';
            
            return studentName.toLowerCase().includes(search.toLowerCase()) &&
                (classFilter === "all" || studentClass === classFilter) &&
                (offerFilter === "all" || offerName === offerFilter) &&
                (schoolFilter === "all" || studentSchool === schoolFilter) &&
                (dateFilter === "" || billDate.startsWith(dateFilter));
        });

        // Sort filtered invoices
        filtered.sort((a, b) => {
            let comparison = 0;
            
            // Handle missing properties during sort
            if (sortField === "totalAmount" || sortField === "teacher_amount") {
                const aValue = parseFloat(a[sortField] || 0);
                const bValue = parseFloat(b[sortField] || 0);
                comparison = aValue - bValue;
            } else {
                const aValue = a[sortField] || '';
                const bValue = b[sortField] || '';
                comparison = aValue > bValue ? 1 : -1;
            }
            
            return sortDirection === "asc" ? comparison : -comparison;
        });

        return filtered;
    }, [safeInvoices, search, classFilter, offerFilter, schoolFilter, dateFilter, sortField, sortDirection]);

    // Calculate summary metrics
    const totalInvoices = filteredInvoices.length;
    const totalAmount = filteredInvoices.reduce((sum, invoice) => 
        sum + parseFloat(invoice?.teacher_amount || 0), 0);
        
    // Calculate the best offer (offer with the highest total amount)
    const bestOffer = useMemo(() => {
        const offerTotals = filteredInvoices.reduce((acc, invoice) => {
            const offerName = invoice?.offer_name || 'Unknown';
            if (!acc[offerName]) {
                acc[offerName] = 0;
            }
            acc[offerName] += parseFloat(invoice?.teacher_amount || 0);
            return acc;
        }, {});

        let bestOfferName = "N/A";
        let bestOfferAmount = 0;

        Object.entries(offerTotals).forEach(([offerName, totalAmount]) => {
            if (totalAmount > bestOfferAmount) {
                bestOfferName = offerName;
                bestOfferAmount = totalAmount;
            }
        });

        return { name: bestOfferName, amount: bestOfferAmount.toFixed(2) };
    }, [filteredInvoices]);
    
    // Calculate total amount for this month
    const currentMonth = new Date().toISOString().slice(0, 7);
    const totalAmountThisMonth = filteredInvoices
        .filter(invoice => invoice?.billDate?.startsWith(currentMonth))
        .reduce((sum, invoice) => sum + parseFloat(invoice?.teacher_amount || 0), 0);

    // Reset all filters
    const resetFilters = () => {
        setSearch("");
        setClassFilter("all");
        setOfferFilter("all");
        setSchoolFilter("all");
        setDateFilter("");
    };

    // Prepare filters for pagination
    const paginationFilters = useMemo(() => ({
        search: search || undefined,
        class: classFilter !== 'all' ? classFilter : undefined,
        offer: offerFilter !== 'all' ? offerFilter : undefined,
        school: schoolFilter !== 'all' ? schoolFilter : undefined,
        date: dateFilter || undefined,
    }), [search, classFilter, offerFilter, schoolFilter, dateFilter]);

    // Handle invoice download
    const handleDownloadInvoice = (invoiceId) => {
        console.log(`Downloading invoice ${invoiceId}`);
        window.open(`/invoices/${invoiceId}/download`, '_blank');
        // Implement download logic here
    };

    const handleBulkDownload = () => {
        if (selectedInvoices.length === 0) {
            return;
        }
        router.post('/invoices/bulk-download', {
            invoiceIds: selectedInvoices
        }, {
            onSuccess: (page) => {
                if (page.props.downloadUrl) {
                    window.location.href = page.props.downloadUrl;
                } else {

                    console.log('Download request completed, check network tab');
                }
            },
            preserveScroll: true
        });
    };
    // Toggle invoice selection
    const toggleInvoiceSelection = (invoiceId) => {
        if (selectedInvoices.includes(invoiceId)) {
            setSelectedInvoices(selectedInvoices.filter(id => id !== invoiceId));
        } else {
            setSelectedInvoices([...selectedInvoices, invoiceId]);
        }
    };

    // Select/deselect all invoices
    const toggleSelectAll = () => {
        if (selectedInvoices.length === filteredInvoices.length) {
            setSelectedInvoices([]);
        } else {
            setSelectedInvoices(filteredInvoices.map(invoice => invoice.id));
        }
    };

    // Define columns for the invoices table
    const columns = [
        {
            header: (
                <div className="flex items-center">
                    <input
                        type="checkbox"
                        className="mr-2 rounded"
                        checked={selectedInvoices.length === filteredInvoices.length && filteredInvoices.length > 0}
                        onChange={toggleSelectAll}
                    />
                    <span className="hidden md:inline">ID</span>
                </div>
            ),
            accessor: "id",
            className: "md:table-cell"
        },
        {
            header: (
                <div className="flex items-center cursor-pointer" onClick={() => handleSort("student_name")}>
                    Student <ArrowUpDown className="ml-1 w-3 h-3" />
                </div>
            ),
            accessor: "student_name"
        },
        {
            header: (
                <div className="flex items-center cursor-pointer" onClick={() => handleSort("student_class")}>
                    Class <ArrowUpDown className="ml-1 w-3 h-3" />
                </div>
            ),
            accessor: "student_class",
            className: "hidden md:table-cell"
        },
        {
            header: (
                <div className="flex items-center cursor-pointer" onClick={() => handleSort("student_school")}>
                    School <ArrowUpDown className="ml-1 w-3 h-3" />
                </div>
            ),
            accessor: "student_school",
            className: "hidden lg:table-cell"
        },
        {
            header: (
                <div className="flex items-center cursor-pointer" onClick={() => handleSort("offer_name")}>
                    Offer <ArrowUpDown className="ml-1 w-3 h-3" />
                </div>
            ),
            accessor: "offer_name",
            className: "hidden lg:table-cell"
        },
        {
            header: (
                <div className="flex items-center cursor-pointer" onClick={() => handleSort("billDate")}>
                    Bill Date <ArrowUpDown className="ml-1 w-3 h-3" />
                </div>
            ),
            accessor: "billDate",
            className: "hidden md:table-cell"
        },
        {
            header: (
                <div className="flex items-center cursor-pointer" onClick={() => handleSort("totalAmount")}>
                    Total <ArrowUpDown className="ml-1 w-3 h-3" />
                </div>
            ),
            accessor: "totalAmount",
            className: "hidden md:table-cell"
        },
        { header: "Actions", accessor: "action" },
    ];

    // Render each row of the table
    const renderRow = (item) => {
        if (!item) return null;
        
        const billDate = item.billDate ? new Date(item.billDate).toLocaleDateString() : 'N/A';
        const teacherAmount = parseFloat(item.teacher_amount || 0).toFixed(1);
        
        return (
            <tr
                key={item.id}
                className={`border-b border-gray-200 text-sm hover:bg-gray-100 ${selectedInvoices.includes(item.id) ? 'bg-blue-50' : 'even:bg-gray-50'}`}
            >
                <td className="p-4">
                    <div className="flex items-center">
                        <input
                            type="checkbox"
                            className="mr-2 rounded"
                            checked={selectedInvoices.includes(item.id)}
                            onChange={() => toggleInvoiceSelection(item.id)}
                            onClick={(e) => e.stopPropagation()}
                        />
                        <span className="md:inline">{item.id}</span>
                    </div>
                </td>
                <td className="p-4 font-medium">{item.student_name || 'Unknown'}</td>
                <td className="p-4 hidden md:table-cell">{item.student_class || '—'}</td>
                <td className="p-4 hidden lg:table-cell">{item.student_school || '—'}</td>
                <td className="p-4 hidden lg:table-cell">{item.offer_name || '—'}</td>
                <td className="p-4 hidden md:table-cell">{billDate}</td>
                <td className="p-4 hidden md:table-cell font-semibold text-green-500">+ {teacherAmount} DH</td>
                <td className="p-4">
                    <div className="flex items-center gap-2">
                        <button
                            onClick={(e) => {
                                e.stopPropagation(); // Prevent row click
                                handleDownloadInvoice(item.id);
                            }}
                            className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaBlue hover:bg-sky-800 transition duration-300 text-white tooltip" data-tip="Download"
                        >
                            <Download className="w-4 h-4" />
                        </button>
                    </div>
                </td>
            </tr>
        );
    };

    return (
        <div className="flex flex-col bg-white rounded-xl shadow-sm p-5 m-4 mt-0">
            {/* Header and Stats */}
            <div className="mb-6">
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                    <div className="flex items-center mb-4 md:mb-0">
                        <FileText className="w-6 h-6 text-blue-600 mr-2" />
                        <h1 className="text-xl font-bold text-gray-800">Teacher Invoices</h1>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <div className="relative">
                            <input
                                type="text"
                                className="border rounded-lg p-2 pl-8 text-sm w-full md:w-44"
                                placeholder="Search student..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <Search className="absolute left-2 top-2.5 w-4 h-4 text-gray-500" />
                            {search && (
                                <button
                                    onClick={() => setSearch("")}
                                    className="absolute right-2 top-2.5"
                                >
                                    <X className="w-4 h-4 text-gray-500 hover:text-gray-700" />
                                </button>
                            )}
                        </div>

                        <button
                            onClick={() => setFiltersVisible(!filtersVisible)}
                            className={`flex items-center gap-1 px-3 py-2 rounded-lg border text-sm 
                ${filtersVisible ? 'bg-blue-50 text-blue-600 border-blue-300' : 'bg-white border-gray-200 hover:bg-gray-50'}`}
                        >
                            <Filter className="w-4 h-4" />
                            Filters
                            <ChevronDown className={`w-4 h-4 transition-transform ${filtersVisible ? 'transform rotate-180' : ''}`} />
                        </button>

                        {(search || classFilter !== "all" || offerFilter !== "all" || dateFilter) && (
                            <button
                                onClick={resetFilters}
                                className="flex items-center gap-1 px-3 py-2 rounded-lg bg-red-50 text-red-600 border border-red-200 text-sm hover:bg-red-100"
                            >
                                <X className="w-4 h-4" />
                                Clear filters
                            </button>
                        )}

                        {selectedInvoices.length > 0 && (
                            <button
                                onClick={handleBulkDownload}
                                className="flex items-center gap-1 px-3 py-2 rounded-lg bg-green-500 text-white text-sm hover:bg-green-600"
                            >
                                <Download className="w-4 h-4" />
                                Download ({selectedInvoices.length})
                            </button>
                        )}
                    </div>
                </div>

                {/* Expanded Filters */}
                {filtersVisible && safeInvoices.length > 0 && (
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 mb-2 bg-gray-50 rounded-lg border border-gray-200 animate-fadeIn">
                        {/* Class Filter */}
                        <div>
                            <label htmlFor="class-filter" className="block text-sm font-medium text-gray-700 mb-1">Class</label>
                            <select
                                id="class-filter"
                                className="border w-full rounded-lg p-2 text-sm"
                                value={classFilter}
                                onChange={(e) => setClassFilter(e.target.value)}
                            >
                                <option value="all">All Classes</option>
                                {uniqueClasses.map((className, index) => (
                                    <option key={index} value={className}>{className}</option>
                                ))}
                            </select>
                        </div>

                        {/* Offer Filter */}
                        <div>
                            <label htmlFor="offer-filter" className="block text-sm font-medium text-gray-700 mb-1">Offer</label>
                            <select
                                id="offer-filter"
                                className="border w-full rounded-lg p-2 text-sm"
                                value={offerFilter}
                                onChange={(e) => setOfferFilter(e.target.value)}
                            >
                                <option value="all">All Offers</option>
                                {uniqueOffers.map((offerName, index) => (
                                    <option key={index} value={offerName}>{offerName || "No Offer"}</option>
                                ))}
                            </select>
                        </div>

                        {/* School Filter */}
                        <div>
                            <label htmlFor="school-filter" className="block text-sm font-medium text-gray-700 mb-1">School</label>
                            <select
                                id="school-filter"
                                className="border w-full rounded-lg p-2 text-sm"
                                value={schoolFilter}
                                onChange={(e) => setSchoolFilter(e.target.value)}
                            >
                                <option value="all">All Schools</option>
                                {uniqueSchools.map((schoolName, index) => (
                                    <option key={index} value={schoolName}>{schoolName || "No School"}</option>
                                ))}
                            </select>
                        </div>

                        {/* Date Filter */}
                        <div>
                            <label htmlFor="date-filter" className="block text-sm font-medium text-gray-700 mb-1">Month</label>
                            <div className="relative">
                                <input
                                    id="date-filter"
                                    type="month"
                                    className="border w-full rounded-lg p-2 pl-8 text-sm"
                                    value={dateFilter}
                                    onChange={(e) => setDateFilter(e.target.value)}
                                />
                                <Calendar className="absolute left-2 top-2.5 w-4 h-4 text-gray-500" />
                                {dateFilter && (
                                    <button
                                        onClick={() => setDateFilter("")}
                                        className="absolute right-2 top-2.5"
                                    >
                                        <X className="w-4 h-4 text-gray-500 hover:text-gray-700" />
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Summary Cards */}
            {safeInvoices.length > 0 && (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div className="bg-gradient-to-r from-blue-50 to-blue-100 p-4 rounded-lg shadow-sm border border-blue-200">
                        <p className="text-sm text-gray-700 font-medium mb-1">Total Invoices</p>
                        <p className="text-2xl font-bold text-blue-800">{totalInvoices}</p>
                    </div>
                    <div className="bg-gradient-to-r from-green-50 to-green-100 p-4 rounded-lg shadow-sm border border-green-200">
                        <p className="text-sm text-gray-700 font-medium mb-1">Total Amount</p>
                        <p className="text-2xl font-bold text-green-800">{totalAmount.toFixed(1)} DH</p>
                    </div>
                    <div className="bg-gradient-to-r from-purple-50 to-purple-100 p-4 rounded-lg shadow-sm border border-purple-200">
                        <p className="text-sm text-gray-700 font-medium mb-1">Best Offer</p>
                        <p className="text-xl font-bold text-purple-800">{bestOffer.name}</p>
                        <p className="text-lg text-purple-700">{bestOffer.amount} DH</p>
                    </div>
                </div>
            )}

            {/* Empty State */}
            {filteredInvoices.length === 0 && (
                <div className="flex flex-col items-center justify-center py-12 bg-gray-50 rounded-lg border border-gray-200">
                    <FileText className="w-12 h-12 text-gray-400 mb-3" />
                    <h3 className="text-lg font-medium text-gray-700 mb-1">No invoices found</h3>
                    <p className="text-gray-500">
                        {safeInvoices.length === 0 
                            ? "There are no invoices available for this teacher." 
                            : "Try adjusting your filters or search criteria"}
                    </p>
                    {safeInvoices.length > 0 && (
                        <button
                            onClick={resetFilters}
                            className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                        >
                            Reset all filters
                        </button>
                    )}
                </div>
            )}

            {/* Invoices Table */}
            {filteredInvoices.length > 0 && (
                <div className="overflow-x-auto">
                    <Table columns={columns} renderRow={renderRow} data={filteredInvoices} />
                </div>
            )}

            {/* Pagination */}
            {invoiceslinks && invoiceslinks.length > 3 && (
                <div className="mt-6">
                    <Pagination links={invoiceslinks} filters={paginationFilters} />
                </div>
            )}
        </div>
    );
};

export default TeacherInvoicesTable;