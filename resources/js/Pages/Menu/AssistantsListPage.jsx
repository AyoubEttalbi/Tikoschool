import { Link, router, usePage } from "@inertiajs/react";
import useFilterNavigation from "@/Hooks/useFilterNavigation";
import { useState, useEffect } from "react";
import { RotateCcw } from "lucide-react";

import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import FormModal from "../../Components/FormModal";
import { Eye } from "lucide-react";
// Define table columns for assistants
const columns = [
    {
        header: "Info",
        accessor: "info",
    },
    {
        header: "Téléphone",
        accessor: "phone",
        className: "hidden md:table-cell",
    },
    {
        header: "E-mail",
        accessor: "email",
        className: "hidden md:table-cell",
    },
    {
        header: "Adresse",
        accessor: "address",
        className: "hidden lg:table-cell",
    },
    {
        header: "Statut",
        accessor: "status",
        className: "hidden md:table-cell",
    },
    {
        header: "Actions",
        accessor: "action",
    },
];

const AssistantsListPage = ({
    assistants = [],
    schools,
    filters: initialFilters,
    search: initialSearch = "",
}) => {
    const pageProps = usePage().props;
    const role = pageProps.auth.user.role;

    // State for filters and search.
    //
    // `search` comes from its own prop: AssistantController passes it separately, not inside
    // `filters`. It was hardcoded to "" here, so reloading /assistants?search=x showed an
    // empty search box over filtered rows.
    const [filters, setFilters] = useState({
        school: initialFilters?.school || "",
        status: initialFilters?.status || "",
        search: initialSearch || "",
    });

    const [showFilters, setShowFilters] = useState(false);
    const [isRestoring, setIsRestoring] = useState(false);
    
    // Get current page from Inertia page props
    const getCurrentPage = () => {
        return pageProps.assistants?.current_page || 1;
    };
    
    // Restore the page you were on when coming back from an assistant profile.
    useEffect(() => {
        const storedState = sessionStorage.getItem('assistantListState');
        if (!storedState) {
            // An `else` branch used to live here: when the URL carried ?page=N it read the
            // query string and router.visit()ed the URL it was ALREADY on, purely to tidy up
            // empty parameters. A whole extra request per paginated view, for nothing.
            return;
        }

        // Consume the entry whether or not we act on it. It used to be removed only inside
        // the restore branch, so an entry that failed the checks stayed in sessionStorage and
        // fired later on an unrelated visit.
        sessionStorage.removeItem('assistantListState');

        try {
            const parsedState = JSON.parse(storedState);
            const maxPage = Math.ceil((assistants?.total || 0) / 10);
            const validPage = Math.min(parsedState.page, maxPage);

            // Only navigate if it would actually change what is on screen. Restoring "page 1,
            // no filters" onto a page that is already page 1 with no filters was a second full
            // page load whose only visible effect was rewriting the URL to
            // ?school=&search=&status=&page=1.
            const alreadyOnPage = validPage === (assistants?.current_page || 1);
            const sameFilters =
                JSON.stringify(parsedState.filters || {}) === JSON.stringify(filters);

            if (validPage > 0
                && Date.now() - parsedState.timestamp < 300000
                && !(alreadyOnPage && sameFilters)) {
                setIsRestoring(true);
                setFilters(parsedState.filters);

                router.get(
                    route("assistants.index"),
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
    }, []);

    // The only place this page navigates for a filter change. AssistantController echoes
    // `search` as its own prop rather than inside `filters`, so it is read from there.
    useFilterNavigation({
        routeName: "assistants.index",
        filters,
        paused: isRestoring,
        serverFilters: {
            school: initialFilters?.school || "",
            status: initialFilters?.status || "",
            search: (initialSearch || "").trim(),
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
            status: "",
            search: "",
        });
    };

    // Toggle visibility of filters
    const toggleFilters = () => {
        setShowFilters(!showFilters);
    };
    
    // Navigate to assistant with preserved state
    const navigateToAssistant = (assistantId) => {
        const currentPage = getCurrentPage();
        const currentState = {
            page: currentPage,
            filters: filters,
            timestamp: Date.now()
        };
        sessionStorage.setItem('assistantListState', JSON.stringify(currentState));
        router.visit(`/assistants/${assistantId}`);
    };

    const renderRow = (assistant) => (
        <tr
            key={assistant.id}
            className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight cursor-pointer"
        >
            <td
                onClick={() => navigateToAssistant(assistant.id)}
                className="flex items-center gap-4 p-4"
            >
                <img
                    src={
                        assistant.profile_image
                            ? assistant.profile_image
                            : "/assistantProfile.png"
                    }
                    alt={`${assistant.first_name} ${assistant.last_name}`}
                    width={40}
                    height={40}
                    className="md:hidden xl:block w-10 h-10 rounded-full object-cover"
                />
                <div className="flex flex-col">
                    <h3 className="font-semibold">{`${assistant.first_name} ${assistant.last_name}`}</h3>
                    <p className="text-xs text-gray-500">ID: {assistant.id}</p>
                </div>
            </td>
            <td className="hidden md:table-cell">{assistant.phone_number}</td>
            <td className="hidden md:table-cell">{assistant.email}</td>
            <td className="hidden lg:table-cell">{assistant.address}</td>
            <td className="hidden md:table-cell">
                <span
                    className={`px-2 py-1 rounded-full text-xs font-medium ${
                        assistant.status === "active"
                            ? "bg-green-100 text-green-600"
                            : "bg-red-100 text-red-600"
                    }`}
                >
                    {assistant.status}
                </span>
            </td>
            <td>
                <div className="flex items-center gap-2">
                    {/* View Button */}
                    <button 
                        onClick={() => navigateToAssistant(assistant.id)}
                        className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky"
                    >
                        <Eye className="w-4 h-4 text-white" />
                    </button>

                    {/* Admin-only actions */}
                    {role === "admin" && (
                        <>
                            {/* Update Assistant */}
                            <FormModal
                                table="assistant"
                                type="update"
                                data={assistant}
                                schools={schools}
                            />

                            {/* Delete Assistant */}
                            <FormModal
                                table="assistant"
                                type="delete"
                                id={assistant.id}
                                route="assistants"
                            />
                        </>
                    )}
                </div>
            </td>
        </tr>
    );

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
            <div className="flex items-center justify-between">
                <h1 className="hidden md:block text-lg font-semibold">
                    Tous les assistants
                </h1>
                <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                    <TableSearch
                        routeName="assistants.index"
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
                                table="assistant"
                                type="create"
                                schools={schools}
                            />
                        )}
                    </div>
                </div>
            </div>

            {/* FILTER FORM */}
            {showFilters && (
                <div className="my-4 p-4 bg-gray-50 rounded-md">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
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

            <Table
                columns={columns}
                renderRow={renderRow}
                data={assistants.data}
            />

            {/* Pagination */}
            <Pagination links={assistants.links} filters={filters} />
        </div>
    );
};

AssistantsListPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default AssistantsListPage;
