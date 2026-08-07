import { router, usePage, Link } from "@inertiajs/react";
import { useState, useEffect, useRef, useMemo } from "react";
import useFilterNavigation from "@/Hooks/useFilterNavigation";
import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Eye, RotateCcw } from "lucide-react";
import FormModal from "../../Components/FormModal";
import FilterForm from "@/Components/FilterForm";
import { motion } from "framer-motion";

const columns = [
    {
        header: "Info",
        accessor: "info",
    },
    {
        header: "ID de l'élève",
        accessor: "studentId",
        className: "hidden md:table-cell",
    },
    {
        header: "Classe",
        accessor: "class",
        className: "hidden md:table-cell",
    },
    {
        header: "Téléphone",
        accessor: "phone",
        className: "hidden lg:table-cell",
    },
    {
        header: "Nom d'offre",
        accessor: "offerNames",
        className: "hidden lg:table-cell",
    },
    {
        header: "Statut d'adhésion",
        accessor: "membershipStatus",
        className: "hidden md:table-cell",
    },
    {
        header: "Actions",
        accessor: "action",
        className: "text-center",
    },
];

const StudentListPage = ({
    students,
    Allclasses,
    Alllevels,
    Allschools,
    filters: initialFilters,
    search: initialSearch = "",
}) => {
    // State for filters and search.
    //
    // `search` comes from its own prop: StudentsController passes it separately, not inside
    // `filters`. Seeding it from `initialFilters.search` (always undefined) meant reloading
    // /students?search=ali showed an empty search box over filtered rows — and the filter
    // effect then "corrected" the server by re-requesting with no search at all.
    const [filters, setFilters] = useState({
        school: initialFilters.school || "",
        class: initialFilters.class || "",
        level: initialFilters.level || "",
        search: initialSearch || "",
        membership_status: initialFilters.membership_status || "all",
    });
    // Ensure students is always an array
    const safeStudents = Array.isArray(students?.data) ? students.data : [];
    // Sort students by created_at descending (latest first)
    const sortedStudents = [...safeStudents].sort(
        (a, b) => new Date(b.created_at) - new Date(a.created_at),
    );

    // Custom search filter for parent phone and parent name (client-side fallback)
    const filteredStudents = filters.search
        ? sortedStudents.filter((student) => {
              const search = filters.search.toLowerCase();
              // Helper to normalize phone numbers (remove spaces, dashes, parentheses, leading +, etc.)
              const normalizePhone = (phone) =>
                  phone
                      ?.replace(/\D/g, "") // Remove all non-digits
                      .replace(/^212/, "0") // Convert +212 or 212 to 0
                      .replace(/^0+/, "0"); // Ensure only one leading zero

              const normalizedSearch = normalizePhone(search);
              const normalizedStudentPhone = normalizePhone(student.phone || "");
              const normalizedGuardianPhone = normalizePhone(student.guardianNumber || "");

              // Also check original phone for partial matches (for +212... search)
              return (
                  (student.name && student.name.toLowerCase().includes(search)) ||
                  (student.studentId && student.studentId.toLowerCase().includes(search)) ||
                  (student.phone && student.phone.toLowerCase().includes(search)) ||
                  (student.offerNames && student.offerNames.toLowerCase().includes(search)) ||
                  (student.guardianNumber && student.guardianNumber.toLowerCase().includes(search)) ||
                  (student.guardianName && student.guardianName.toLowerCase().includes(search)) ||
                  (normalizedSearch && normalizedStudentPhone.includes(normalizedSearch)) ||
                  (normalizedSearch && normalizedGuardianPhone.includes(normalizedSearch))
              );
          })
        : sortedStudents;
    const pageProps = usePage().props;
    const role = pageProps.auth.user.role;

    const [showFilters, setShowFilters] = useState(false);
    const [isRestoring, setIsRestoring] = useState(false);
    
    // Get current page from Inertia page props
    const getCurrentPage = () => {
        return pageProps.students?.current_page || 1;
    };
    
    // Restore the page you were on when coming back from a student profile.
    useEffect(() => {
        const storedState = sessionStorage.getItem('studentListState');
        if (!storedState) {
            // An `else` branch used to live here: when the URL carried ?page=N it read the
            // query string and router.visit()ed the URL it was ALREADY on, purely to tidy up
            // empty parameters. A whole extra request per paginated view, for nothing.
            return;
        }

        // Consume the entry whether or not we act on it. It used to be removed only inside
        // the restore branch, so an entry that failed the checks stayed in sessionStorage and
        // fired later on an unrelated visit.
        sessionStorage.removeItem('studentListState');

        try {
            const parsedState = JSON.parse(storedState);
            const maxPage = pageProps.students?.last_page || 1;
            const validPage = Math.min(parsedState.page, maxPage);

            // Only navigate if it would actually change what is on screen. Restoring "page 1,
            // no filters" onto a page that is already page 1 with no filters was a second full
            // page load whose only visible effect was rewriting the URL to
            // ?class=&level=&page=1&school=&search= — the redirect you see after a reload.
            const alreadyOnPage = validPage === (pageProps.students?.current_page || 1);
            const sameFilters =
                JSON.stringify({ ...filters, ...parsedState.filters }) === JSON.stringify(filters);

            if (Date.now() - parsedState.timestamp >= 5 * 60 * 1000
                || (alreadyOnPage && sameFilters)) {
                return;
            }

            setIsRestoring(true);
            setFilters((prevFilters) => ({ ...prevFilters, ...parsedState.filters }));

            router.get(route("students.index"), {
                ...parsedState.filters,
                page: validPage
            }, {
                preserveState: true,
                replace: true,
                preserveScroll: true,
                onFinish: () => setIsRestoring(false),
            });
        } catch (error) {
            // Malformed entry — already removed above, nothing to restore.
        }
    }, []);
    
    // The only place this page navigates for a filter change. StudentsController echoes
    // `search` as its own prop rather than inside `filters`, so it is read from there.
    useFilterNavigation({
        routeName: "students.index",
        filters,
        paused: isRestoring,
        serverFilters: {
            school: initialFilters.school || "",
            class: initialFilters.class || "",
            level: initialFilters.level || "",
            search: (initialSearch || "").trim(),
            membership_status: initialFilters.membership_status || "all",
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
            level: "",
            search: "",
            membership_status: "all",
        });
    };

    // Toggle visibility of filters
    const toggleFilters = () => {
        setShowFilters(!showFilters);
    };
    
    // Navigate to student with preserved state
    const navigateToStudent = (studentId) => {
        const currentPage = getCurrentPage();
        
        // Store the current state in sessionStorage for back navigation
        const currentState = {
            page: currentPage,
            filters: filters,
            timestamp: Date.now()
        };
        sessionStorage.setItem('studentListState', JSON.stringify(currentState));
        
        // Navigate to student page
        router.visit(`/students/${studentId}`);
    };

    // Render table rows
    // O(1) lookups instead of Array.find() per row per render.
    //
    // renderRow ran `Alllevels.find(...)` and `Allclasses.find(...)` for every row, on
    // every render, against arrays that are already fully in memory — O(rows x levels).
    // The table is server-paginated so the practical cost is small, but a Map is both
    // faster and clearer, and these lists grow with the number of schools.
    const levelsById = useMemo(
        () => new Map((Alllevels ?? []).map((level) => [level.id, level])),
        [Alllevels]
    );
    const classesById = useMemo(
        () => new Map((Allclasses ?? []).map((group) => [group.id, group])),
        [Allclasses]
    );

    const renderRow = (item) => {

        return (
            <tr
                key={item.id}
                className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight"
            >
                <td
                    onClick={role !== "teacher" ? () => navigateToStudent(item.id) : undefined}
                    className="flex items-center gap-4 p-4 cursor-pointer"
                >

                <img
                    src={
                        item.profile_image
                            ? item.profile_image
                            : "/studentProfile.png"
                    }
                    alt={item.name}
                    width={40}
                    height={40}
                    className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
                />
                <div className="flex flex-col">
                    <h3 className="font-semibold">{item.name}</h3>
                    <p className="text-xs text-gray-500">
                        {levelsById.get(item.levelId)?.name}
                    </p>
                </div>
            </td>
            <td className="hidden md:table-cell">{item.id}</td>
            <td className="hidden md:table-cell">
                {classesById.get(item.classId)?.name}
            </td>
            <td className="hidden md:table-cell">{item.guardianNumber}</td>
            <td className="hidden lg:table-cell">{item.offerNames || '-'}</td>
            <td className="hidden md:table-cell">
                {(() => {
                    const status = item.paymentStatus;
                    
                    // No memberships
                    if (!status || status.total === 0) {
                        return (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                Aucune
                            </span>
                        );
                    }

                    // Determine priority status to display
                    if (status.unpaid > 0) {
                        return (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                {status.unpaid} non payée{status.unpaid > 1 ? 's' : ''}
                            </span>
                        );
                    }
                    
                    if (status.partial > 0) {
                        return (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                {status.partial} partielle{status.partial > 1 ? 's' : ''}
                            </span>
                        );
                    }
                    
                    // All paid
                    return (
                        <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                            Tout payé
                        </span>
                    );
                })()}
            </td>
            <td className=" p-4">
                <div className="flex items-center gap-2 justify-center">

                    {(role === "admin" || role === "assistant") && (
                        <>
                            <button 
                                onClick={() => navigateToStudent(item.id)}
                                className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky"
                            >
                                <Eye className="w-4 h-4 text-white" />
                            </button>
                            <FormModal
                                table="student"
                                type="update"
                                data={item}
                                levels={Alllevels}
                                classes={Allclasses}
                                schools={Allschools}
                            />
                            <FormModal
                                table="student"
                                type="delete"
                                id={item.id}
                                route="students"
                            />
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
            <div className="flex items-center justify-between">
                <h1 className="hidden md:block text-lg font-semibold">
                    Tous les étudiants
                </h1>
                <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                    <TableSearch
                        routeName="students.index"
                        value={filters.search}
                        onChange={(value) =>
                            setFilters((prev) => ({ ...prev, search: value }))
                        }
                    />

                    <div className="flex items-center gap-4 self-end">
                        <button
                            onClick={clearFilters}
                            className="w-8 h-8 flex  items-center justify-center rounded-full bg-lamaYellow"
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

                        <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
                            <img
                                src="/sort.png"
                                alt="Sort"
                                width={14}
                                height={14}
                            />
                        </button>
                        {(role === "admin" || role === "assistant") && (
                            <FormModal
                                table="student"
                                type="create"
                                levels={Alllevels}
                                classes={Allclasses}
                                schools={Allschools}
                            />
                        )}
                    </div>
                </div>
            </div>

            {/* FILTER FORM */}
            {showFilters && (
                <FilterForm
                    schools={Allschools}
                    classes={Allclasses}
                    levels={Alllevels}
                    filters={filters}
                    onFilterChange={handleFilterChange}
                />
            )}

            {/* LIST */}
            <Table
                columns={columns}
                data={filteredStudents}
                renderRow={renderRow}
                emptyText="Aucun étudiant trouvé."
            />
            <Pagination links={students.links} filters={filters} />
        </div>
    );
};

StudentListPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default StudentListPage;
