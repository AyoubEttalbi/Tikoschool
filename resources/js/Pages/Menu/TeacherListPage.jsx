import FormModal from "@/Components/FormModal";
import Pagination from "@/Components/Pagination";
import Table from "@/Components/Table";
import TableSearch from "@/Components/TableSearch";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Link, router, usePage } from "@inertiajs/react";
import { Eye, RotateCcw } from "lucide-react";
import { useState, useEffect } from "react";
import useFilterNavigation from "@/Hooks/useFilterNavigation";
import FilterForm from "@/Components/FilterForm";
import { motion } from "framer-motion";

const TeacherListPage = ({
    teachers,
    subjects,
    classes,
    schools,
    filters: initialFilters,
}) => {
    const pageProps = usePage().props;
    const role = pageProps.auth.user.role;

    const columns = [
        {
            header: "Info",
            accessor: "info",
        },
        {
            header: "ID Enseignant",
            accessor: "teacherId",
            className: "hidden md:table-cell",
        },
        {
            header: "Matières",
            accessor: "subjects",
            className: "hidden md:table-cell",
        },
        {
            header: "Classes",
            accessor: "classes",
            className: "hidden md:table-cell",
        },
        {
            header: "Téléphone",
            accessor: "phone",
            className: "hidden lg:table-cell",
        },
        {
            header: "Adresse",
            accessor: "address",
            className: "hidden lg:table-cell",
        },
        {
            header: "Statut",
            accessor: "status",
            className: "hidden lg:table-cell",
        },
        {
            header: "Actions",
            accessor: "action",
            show: role === "admin", // Only show if admin
        },
    ];

    // Ensure teachers is always an array
    const safeTeachers = Array.isArray(teachers?.data) ? teachers.data : [];
    // Sort teachers by created_at descending (latest first)
    const sortedTeachers = [...safeTeachers].sort(
        (a, b) => new Date(b.created_at) - new Date(a.created_at),
    );

    // State for filters and search.
    //
    // `search` comes from its own prop: TeacherController passes it separately, not inside
    // `filters`. Seeding it from `initialFilters.search` (always undefined) meant reloading
    // /teachers?search=x showed an empty search box over filtered rows.
    const [filters, setFilters] = useState({
        school: initialFilters.school || "",
        class: initialFilters.class || "",
        subject: initialFilters.subject || "",
        status: initialFilters.status || "",
        search: pageProps.search || "",
    });

    const [showFilters, setShowFilters] = useState(false);
    const [isRestoring, setIsRestoring] = useState(false);
    
    // Get current page from Inertia page props
    const getCurrentPage = () => {
        return pageProps.teachers?.current_page || 1;
    };
    
    // Check for stored state or URL page parameter on component mount
    useEffect(() => {
        // First check for stored state from navigation
        const storedState = sessionStorage.getItem('teacherListState');
        if (storedState) {
            // Consume the entry up front, whether or not we act on it. It used to be removed
            // only inside the restore branch, so an entry that failed the checks below stayed
            // in sessionStorage and fired on some later, unrelated visit to this page.
            sessionStorage.removeItem('teacherListState');

            try {
                const parsedState = JSON.parse(storedState);
                const maxPage = Math.ceil((teachers?.total || 0) / 10);
                const validPage = Math.min(parsedState.page, maxPage);

                // Only navigate if it would actually change what is on screen. Restoring
                // "page 1 with no filters" onto a page that is already page 1 with no filters
                // was a second full page load whose only visible effect was rewriting the URL
                // to ?class=&page=1&school=&search=&status=&subject= — which is exactly what
                // you see after reloading /teachers.
                const alreadyOnPage = validPage === (teachers?.current_page || 1);
                const sameFilters =
                    JSON.stringify(parsedState.filters || {}) === JSON.stringify(filters);

                if (validPage > 0
                    && Date.now() - parsedState.timestamp < 300000
                    && !(alreadyOnPage && sameFilters)) {
                    setIsRestoring(true);
                    setFilters(parsedState.filters);
                    
                    router.get(
                        route("teachers.index"),
                        { ...parsedState.filters, page: validPage },
                        {
                            preserveState: true,
                            replace: true,
                            preserveScroll: true,
                            onFinish: () => setIsRestoring(false),
                        }
                    );
                }
            } catch (error) {
                // Malformed entry — already removed above, nothing to restore.
            }
        }
        // An `else` branch used to live here: when the URL carried ?page=N it read the query
        // string and router.visit()ed the URL it was ALREADY on, purely to tidy up empty
        // parameters. A whole extra request per paginated view, for nothing.
    }, []);

    // The only place this page navigates for a filter change. TeacherController echoes
    // `search` as its own prop rather than inside `filters`, so it is read from there.
    useFilterNavigation({
        routeName: "teachers.index",
        filters,
        paused: isRestoring,
        serverFilters: {
            school: initialFilters.school || "",
            class: initialFilters.class || "",
            subject: initialFilters.subject || "",
            status: initialFilters.status || "",
            search: (pageProps.search || "").trim(),
        },
    });

    // Handle filter changes
    const handleFilterChange = (e) => {
        const { name, value } = e.target;

        // Trim search value if it's the search field
        const cleanValue = name === 'search' ? value.trim() : value;

        setFilters({ ...filters, [name]: cleanValue });
    };

    // Clear filters and reset the page
    const clearFilters = () => {
        setFilters({
            school: "",
            class: "",
            subject: "",
            status: "",
            search: "",
        });
    };

    // Toggle visibility of filters
    const toggleFilters = () => {
        setShowFilters(!showFilters);
    };
    
    // Navigate to teacher with preserved state
    const navigateToTeacher = (teacherId) => {
        const currentPage = getCurrentPage();
        const currentState = {
            page: currentPage,
            filters: filters,
            timestamp: Date.now()
        };
        sessionStorage.setItem('teacherListState', JSON.stringify(currentState));
        router.visit(`/teachers/${teacherId}`);
    };

    // Render table rows
    const renderRow = (item) => (
        <tr
            key={item.id}
            className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight cursor-pointer"
        >
            <td
                className="flex items-center gap-4 p-4"
                onClick={() => {
                    if (role === "admin") navigateToTeacher(item.id);
                }}
            >
                <img
                    src={
                        item.profile_image
                            ? item.profile_image
                            : "/teacherPrfile2.png"
                    }
                    alt={item.name}
                    width={40}
                    height={40}
                    className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
                />
                <div className="flex flex-col">
                    <h3 className="font-semibold">{item.name}</h3>
                    <p className="text-xs text-gray-500">{item?.email}</p>
                </div>
            </td>
            <td className="hidden md:table-cell">{item.id}</td>
            <td className="hidden md:table-cell">
                {item.subjects.map((subject) => subject.name).join(", ")}
            </td>
            <td className="hidden md:table-cell">
                {item.classes.map((group) => group.name).join(", ")}
            </td>
            <td className="hidden md:table-cell">{item.phone}</td>
            <td className="hidden md:table-cell">{item.address}</td>
            <td className="hidden lg:table-cell">
                <span
                    className={`px-2 py-1 rounded-full text-xs ${
                        item.status === "active"
                            ? "bg-green-100 text-green-800"
                            : "bg-red-100 text-red-800"
                    }`}
                >
                    {item.status}
                </span>
            </td>
            <td>
                <div className="flex items-center gap-2">
                    {role === "admin" && (
                        <button 
                            onClick={() => navigateToTeacher(item.id)}
                            className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky"
                        >
                            <Eye className="w-4 h-4 text-white" />
                        </button>
                    )}
                    {role === "admin" && (
                        <>
                            <FormModal
                                table="teacher"
                                type="update"
                                id={item.id}
                                data={item}
                                schools={schools}
                                subjects={subjects}
                                classes={classes}
                            />
                            <FormModal
                                table="teacher"
                                type="delete"
                                id={item.id}
                                route="teachers"
                            />
                        </>
                    )}
                </div>
            </td>
        </tr>
    );

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
            {/* TOP */}
            <div className="flex items-center justify-between">
                <h1 className="hidden md:block text-lg font-semibold">
                    Tous les enseignants
                </h1>
                <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                    <TableSearch
                        routeName="teachers.index"
                        value={filters.search}
                        onChange={(value) =>
                            setFilters((prev) => ({ ...prev, search: value }))
                        }
                    />

                    <div className="flex items-center gap-4 self-end">
                        <button
                            onClick={clearFilters}
                            className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow"
                        >
                            <RotateCcw className="w-4 h-4 text-black" />
                        </button>
                        <button
                            onClick={toggleFilters}
                            className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow"
                        >
                            <img
                                src="/filter.png"
                                alt="Filter"
                                width={14}
                                height={14}
                            />
                        </button>

                        {role === "admin" && (
                            <FormModal
                                table="teacher"
                                type="create"
                                schools={schools}
                                subjects={subjects}
                                classes={classes}
                            />
                        )}
                    </div>
                </div>
            </div>

            {/* FILTER FORM */}
            {showFilters && (
                <div className="my-4 p-4 bg-gray-50 rounded-md">
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                École
                            </label>
                            <select
                                name="school"
                                value={filters.school}
                                onChange={handleFilterChange}
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
                            >
                                <option value="">Toutes les écoles</option>
                                {schools.map((school) => (
                                    <option key={school.id} value={school.id}>
                                        {school.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Classe
                            </label>
                            <select
                                name="class"
                                value={filters.class}
                                onChange={handleFilterChange}
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
                            >
                                <option value="">Toutes les classes</option>
                                {classes.map((cls) => (
                                    <option key={cls.id} value={cls.id}>
                                        {cls.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Matière
                            </label>
                            <select
                                name="subject"
                                value={filters.subject}
                                onChange={handleFilterChange}
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
                            >
                                <option value="">Toutes les matières</option>
                                {subjects.map((subject) => (
                                    <option key={subject.id} value={subject.id}>
                                        {subject.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Statut
                            </label>
                            <select
                                name="status"
                                value={filters.status}
                                onChange={handleFilterChange}
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
                            >
                                <option value="">Tous les statuts</option>
                                <option value="active">Actif</option>
                                <option value="inactive">Inactif</option>
                            </select>
                        </div>
                    </div>
                </div>
            )}

            {/* LIST */}
            <Table
                columns={columns.filter(
                    (col) => col.show === undefined || col.show,
                )}
                data={sortedTeachers}
                renderRow={renderRow}
                emptyText="Aucun enseignant trouvé."
            />
            
            {/* PAGINATION */}
            <Pagination links={teachers.links} filters={filters} />
        </div>
    );
};

TeacherListPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default TeacherListPage;
