import { FaChartLine, FaEdit } from 'react-icons/fa';

const ScoresTable = ({ scores }) => {
  return (
    <div className="mb-8">
      <h2 className="text-xl font-bold mb-4 flex items-center">
        <FaChartLine className="mr-2" /> Scores
      </h2>
      <table className="w-full border-collapse">
        <thead>
          <tr className="bg-gray-200">
            <th className="p-2 border">Level</th>
            <th className="p-2 border">Teacher</th>
            <th className="p-2 border">Grade 1</th>
            <th className="p-2 border">Grade 2</th>
            <th className="p-2 border">Grade 3</th>
            <th className="p-2 border">Note</th>
            <th className="p-2 border">Action</th>
          </tr>
        </thead>
        <tbody>
          {scores.map((score, index) => (
            <tr key={index} className="hover:bg-gray-100">
              <td className="p-2 border">{score.level}</td>
              <td className="p-2 border">{score.teacher}</td>
              <td className="p-2 border">{score.grade1}</td>
              <td className="p-2 border">{score.grade2}</td>
              <td className="p-2 border">{score.grade3}</td>
              <td className="p-2 border">{score.note || '---'}</td>
              <td className="p-2 border">
                <button className="text-blue-500 hover:text-blue-700">
                  <FaEdit />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default ScoresTable;