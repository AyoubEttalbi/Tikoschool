import { FaCalendarAlt, FaEdit } from 'react-icons/fa';

const AbsenceLogTable = ({ absences }) => {
  return (
    <div className="mb-8">
      <h2 className="text-xl font-bold mb-4 flex items-center">
        <FaCalendarAlt className="mr-2" /> Absence Log
      </h2>
      <table className="w-full border-collapse">
        <thead>
          <tr className="bg-gray-200">
            <th className="p-2 border">Date</th>
            <th className="p-2 border">Level</th>
            <th className="p-2 border">Teacher</th>
            <th className="p-2 border">Status</th>
            <th className="p-2 border">Note</th>
            <th className="p-2 border">Action</th>
          </tr>
        </thead>
        <tbody>
          {absences.map((absence, index) => (
            <tr key={index} className="hover:bg-gray-100">
              <td className="p-2 border">{absence.date}</td>
              <td className="p-2 border">{absence.level}</td>
              <td className="p-2 border">{absence.teacher}</td>
              <td className="p-2 border">{absence.status}</td>
              <td className="p-2 border">{absence.note || '---'}</td>
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

export default AbsenceLogTable;