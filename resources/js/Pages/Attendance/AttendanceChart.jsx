
import {
  BarChart,
  Bar,
  Rectangle,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from "recharts";
import { useEffect, useState } from "react";
import axios from "axios";

const AttendanceChart = () => {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await axios.get(route('attendance.stats'));
        setData(response.data);
      } catch (error) {
        console.error('Error fetching attendance data:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);
  console.log("Attendance data:", data);
  return (
    <div className="bg-white rounded-lg p-4 h-full">
      <div className="flex justify-between items-center">
        <h1 className="text-lg font-semibold">Weekly Attendance Summary</h1>
      </div>
      {loading ? (
        <div className="flex items-center justify-center h-full">
          <div className="text-gray-500">Loading attendance data...</div>
        </div>
      ) : data.length === 0 ? (
        <div className="flex items-center justify-center h-full">
          <div className="text-gray-500">No attendance data available</div>
        </div>
      ) : (
        <ResponsiveContainer width="100%" height="90%">
          <BarChart width={500} height={300} data={data} barSize={20}>
            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#ddd" />
            <XAxis
              dataKey="name"
              axisLine={false}
              tick={{ fill: "#d1d5db" }}
              tickLine={false}
            />
            <YAxis axisLine={false} tick={{ fill: "#d1d5db" }} tickLine={false} />
            <Tooltip
              contentStyle={{ borderRadius: "10px", borderColor: "lightgray" }}
            />
            <Legend
              align="left"
              verticalAlign="top"
              wrapperStyle={{ paddingTop: "20px", paddingBottom: "40px" }}
            />
            <Bar
              dataKey="present"
              name="Present"
              fill="#A0D8EF"
              legendType="circle"
              radius={[10, 10, 0, 0]}
            />
            <Bar
              dataKey="absent"
              name="Absent"
              fill="#B39DDB"
              legendType="circle"
              radius={[10, 10, 0, 0]}
            />
            <Bar
              dataKey="late"
              name="Late"
              fill="#FFD54F"
              legendType="circle"
              radius={[10, 10, 0, 0]}
            />
          </BarChart>
        </ResponsiveContainer>
      )}
    </div>
  );
};

export default AttendanceChart;
