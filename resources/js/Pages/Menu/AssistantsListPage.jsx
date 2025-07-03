import { Link, router, usePage } from "@inertiajs/react";
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
}) => {
    const role = usePage().props.auth.user.role;

    // State for filters and search
    const [filters, setFilters] = useState({
        school: initialFilters?.school || "",
        status: initialFilters?.status || "",
        search: "",
    });

    const [showFilters, setShowFilters] = useState(false);

    // Debounced function to apply filters
    useEffect(() => {
        const timeoutId = setTimeout(() => {
            router.get(
                route("assistants.index"),
                { ...filters },
                { preserveState: true, replace: true, preserveScroll: true },
            );
        }, 300);

        return () => clearTimeout(timeoutId);
    }, [filters]);

    // Handle filter changes
    const handleFilterChange = (e) => {
        const { name, value } = e.target;
        const newFilters = { ...filters, [name]: value };
        setFilters(newFilters);

        router.get(
            route("assistants.index"),
            {
                ...newFilters,
                page: 1,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    // Clear filters and reset the page
    const clearFilters = () => {
        setFilters({
            school: "",
            status: "",
            search: "",
        });

        router.get(
            route("assistants.index"),
            {},
            { preserveState: false, replace: true, preserveScroll: true },
        );
    };

    // Toggle visibility of filters
    const toggleFilters = () => {
        setShowFilters(!showFilters);
    };

    const renderRow = (assistant) => (
        <tr
            key={assistant.id}
            className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight cursor-pointer"
        >
            <td
                onClick={() => router.visit(`/assistants/${assistant.id}`)}
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
                    <Link href={`assistants/${assistant.id}`}>
                        <button className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaSky">
                            <Eye className="w-4 h-4 text-white" />
                        </button>
                    </Link>

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
