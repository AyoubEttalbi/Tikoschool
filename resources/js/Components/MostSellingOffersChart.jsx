import {
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer,
} from "recharts";
import { usePage } from "@inertiajs/react";

const MostSellingOffersChart = ({ schoolId }) => {
    const { props } = usePage();
    // Data for the chart, filtered by schoolId if provided
    const data = props.mostSellingOffers
        .filter((offer) => !schoolId || String(offer.school_id) === String(schoolId))
        .map((offer) => ({
            name: offer.name,
            students: offer.student_count,
            totalPrice: offer.total_price,
        }));

    return (
        <div className="bg-white rounded-xl w-full h-[400px] p-4">
            <h1 className="text-lg font-semibold mb-4">
                Offres les plus vendues
            </h1>
            <ResponsiveContainer width="100%" height="90%">
                <BarChart
                    data={data}
                    margin={{
                        top: 5,
                        right: 30,
                        left: 20,
                        bottom: 5,
                    }}
                >
                    <CartesianGrid strokeDasharray="3 3" stroke="#ddd" />
                    <XAxis
                        dataKey="name"
                        axisLine={false}
                        tick={{ fill: "grey" }}
                        tickLine={false}
                    />
                    {/* YAxis for students */}
                    <YAxis
                        yAxisId="left"
                        axisLine={false}
                        tick={{ fill: "black" }}
                        tickLine={false}
                        label={{
                            value: "Étudiants",
                            angle: -90,
                            position: "insideLeft",
                        }}
                    />
                    {/* YAxis for total price */}
                    <YAxis
                        yAxisId="right"
                        orientation="right"
                        axisLine={false}
                        tick={{ fill: "black" }}
                        tickLine={false}
                        label={{
                            value: "Prix total",
                            angle: -90,
                            position: "insideRight",
                        }}
                    />
                    <Tooltip />
                    <Legend />
                    {/* Bar for students */}
                    <Bar
                        yAxisId="left"
                        dataKey="students"
                        fill="#C3EBFA"
                        name="Étudiants"
                    />
                    {/* Bar for total price */}
                    <Bar
                        yAxisId="right"
                        dataKey="totalPrice"
                        fill="#CFCEFF"
                        name="Prix total"
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default MostSellingOffersChart;
