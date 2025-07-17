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

const FinanceChart = ({ schoolId }) => {
    const { props } = usePage();
    // Filter data by school_id
    const filteredData =
        schoolId
            ? props.monthlyIncomes.filter(
                  (income) => String(income.school_id) === String(schoolId)
              )
            : props.monthlyIncomes;

    return (
        <div className="bg-white rounded-xl w-full h-full p-4">
            <div className="flex justify-between items-center">
                <h1 className="text-lg font-semibold">Finance</h1>
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
