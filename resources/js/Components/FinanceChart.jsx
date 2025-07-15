import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "./ui/select";
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer,
} from "recharts";
import { usePage } from "@inertiajs/react";
import { useState } from "react";

const FinanceChart = () => {
    const [school, setSchool] = useState("all");
    const [open, setOpen] = useState(false);
    const { props } = usePage();
    console.log("props", props.schools);
    // Filter data by school_id
    const filteredData =
        school === "all"
            ? props.monthlyIncomes
            : props.monthlyIncomes.filter(
                  (income) => String(income.school_id) === String(school)
              );

    return (
        <div className="bg-white rounded-xl w-full h-full p-4">
            <div className="flex justify-between items-center">
                <h1 className="text-lg font-semibold">Finance</h1>
                <button onClick={() => setOpen(!open)} className="relative">
                    <img src="/moreDark.png" alt="" width={20} height={20} />
                    {open && (
                        <div className="absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-lg z-10">
                            <Select
                                value={school}
                                onValueChange={(value) => {
                                    setSchool(value);
                                    setOpen(false); // Close dropdown after selection
                                }}
                            >
                                <SelectTrigger className="w-full bg-white border-none shadow-none">
                                    <SelectValue>
                                        {school === "all"
                                            ? "Toutes les écoles"
                                            : props.schools.find((s) => String(s.id) === String(school))?.name || ""}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent className="bg-white rounded-lg shadow-md">
                                    <SelectItem
                                        value="all"
                                        className="cursor-pointer hover:bg-gray-100 p-2"
                                    >
                                        Toutes les écoles
                                    </SelectItem>
                                    {props.schools.map((schoolObj) => (
                                        <SelectItem
                                            key={schoolObj.id}
                                            value={String(schoolObj.id)}
                                            className="cursor-pointer hover:bg-gray-100 p-2"
                                        >
                                            {schoolObj.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                </button>
            </div>
            <ResponsiveContainer width="100%" height="90%">
                <LineChart
                    width={500}
                    height={300}
                    data={filteredData}
                    margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                >
                    <CartesianGrid strokeDasharray="3 3" stroke="#ddd" />
                    <XAxis
                        dataKey="name"
                        axisLine={false}
                        tick={{ fill: "#d1d5db" }}
                        tickLine={false}
                        tickMargin={10}
                    />
                    <YAxis
                        axisLine={false}
                        tick={{ fill: "#d1d5db" }}
                        tickLine={false}
                        tickMargin={20}
                    />
                    <Tooltip />
                    <Legend
                        align="center"
                        verticalAlign="top"
                        wrapperStyle={{
                            paddingTop: "10px",
                            paddingBottom: "30px",
                        }}
                    />
                    <Line
                        type="monotone"
                        dataKey="income"
                        stroke="#C3EBFA"
                        strokeWidth={5}
                    />
                    <Line
                        type="monotone"
                        dataKey="expense"
                        stroke="#CFCEFF"
                        strokeWidth={5}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default FinanceChart;
