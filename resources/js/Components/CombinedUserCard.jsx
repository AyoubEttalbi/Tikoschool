import React, { useState } from "react";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "./ui/select";
import { router } from "@inertiajs/react";
import { UserPlus, UserX } from "lucide-react";

const getMonthOptions = () => {
    // Last 12 months
    const options = [];
    const now = new Date();
    for (let i = 0; i < 12; i++) {
        const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
        options.push({
            value: date.toISOString().slice(0, 7),
            label: date.toLocaleString("default", { month: "long", year: "numeric" })
        });
    }
    return options;
};

const getCurrentSchoolYear = () => {
    const now = new Date();
    const year = now.getMonth() >= 7 ? now.getFullYear() : now.getFullYear() - 1;
    return `${year}/${(year + 1).toString().slice(-2)}`;
};

const CombinedUserCard = ({ stats }) => {
    const [open, setOpen] = useState(false);
    const [studentStatsMonth, setStudentStatsMonth] = useState(stats?.month || getMonthOptions()[0].value);
    const monthOptions = getMonthOptions();
    const selectedMonthLabel = monthOptions.find(opt => opt.value === studentStatsMonth)?.label || "Mois";

    // Show stats (fallback to 0 if missing)
    const inscribed = stats?.inscribed ?? 0;
    const abandoned = stats?.abandoned ?? 0;

    // Handle filter changes
    const handleMonthChange = (newMonth) => {
        setStudentStatsMonth(newMonth);
        setOpen(false);
        router.get(route("dashboard"), { student_stats_month: newMonth }, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="rounded-2xl odd:bg-lamaPurple even:bg-lamaYellow p-4 flex-1 min-w-[130px] relative cursor-pointer hover:shadow-lg transition-shadow">
            <div className="flex justify-between items-center">
                <span className="text-[10px] bg-white px-2 py-1 rounded-full text-green-600">
                    {getCurrentSchoolYear()}
                </span>
                <button onClick={() => setOpen(!open)} className="ml-2 ">
                    <img src="/more.png" alt="filter" width={20} height={20} />
                </button>
            </div>
            <div className="my-2 flex flex-col gap-2">
                <div className="flex items-center gap-2  text-blue-500">
                    <UserPlus className="w-5 h-5 text-blue-500" />
                    <span className="font-semibold">Inscrits:</span>
                    <span className="text-xl font-bold  text-blue-500">{inscribed}</span>
                </div>
                <div className="flex items-center gap-2 text-red-500">
                    <UserX className="w-5 h-5 text-red-500" />
                    <span className="font-semibold">Abandons:</span>
                    <span className="text-xl font-bold text-red-500">{abandoned}</span>
                </div>
            </div>
            <h2 className="capitalize text-sm font-medium text-gray-500">Mouvement mensuel</h2>
            {/* Dropdown for month filter */}
            {open && (
                <div className="absolute top-8 right-2 mt-2 w-48 bg-white rounded-lg shadow-lg z-10 p-2">
                    <Select value={studentStatsMonth} onValueChange={handleMonthChange}>
                        <SelectTrigger className="w-full bg-white border-none shadow-none">
                            <SelectValue>{selectedMonthLabel}</SelectValue>
                        </SelectTrigger>
                        <SelectContent className="bg-white rounded-lg shadow-md">
                            {monthOptions.map((opt) => (
                                <SelectItem key={opt.value} value={opt.value} className="cursor-pointer hover:bg-gray-100 p-2">
                                    {opt.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}
        </div>
    );
};

export default CombinedUserCard; 