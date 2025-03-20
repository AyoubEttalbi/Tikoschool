import { FaCalendarAlt, FaEdit, FaExclamationTriangle } from 'react-icons/fa';
import { format } from 'date-fns';
import FormModal from './FormModal';
import AttendanceModal from '@/Pages/Attendance/AttendanceModal';
import { useState } from 'react';
import { Edit } from 'lucide-react';
const AbsenceLogTable = ({ absences }) => {
  const [showUpdateModal, setShowUpdateModal] = useState(false);
  // Function to format the date
  const formatDate = (dateString) => {
    try {
      return format(new Date(dateString), 'MMM dd, yyyy');
    } catch (error) {
      return dateString;
    }
  };
  
  // Function to determine status badge style
  const getStatusBadge = (status) => {
    switch (status.toLowerCase()) {
      case 'absent':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
            <FaExclamationTriangle className="mr-1" />
            Absent
          </span>
        );
      case 'late':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
            Late
          </span>
        );
      case 'present':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
            Present
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
            {status}
          </span>
        );
    }
  };

  return (
    <div className="mb-8 bg-white rounded-lg shadow-md overflow-hidden">
      <div className="p-4 bg-gray-200 text-black">
        <h2 className="text-xl font-bold flex items-center">
          <FaCalendarAlt className="mr-2" /> Absence Log
        </h2>
      </div>
      
      <div className="overflow-x-auto">
        <table className="w-full border-collapse">
          <thead>
            <tr className="bg-gray-50 border-b border-gray-200">
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recorded By</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
              <th className="p-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {absences.map((absence) => (
              <tr key={absence.id} className="hover:bg-gray-50 transition-colors duration-150">
                <td className="p-3 text-sm text-gray-900">{formatDate(absence.date)}</td>
                <td className="p-3 text-sm text-gray-900">{absence.classe}</td>
                <td className="p-3 text-sm text-gray-900">{absence.recordedBy}</td>
                <td className="p-3 text-sm text-gray-900">{getStatusBadge(absence.status)}</td>
                <td className="p-3 text-sm text-gray-900">{absence.reason || '---'}</td>
                <td className="p-3 text-sm text-gray-900">
                  { showUpdateModal &&(
                    <AttendanceModal 
                    table = 'attendances'
                    id = {absence.id}
                    data={absence}
                    type="update"
                    onClose={() => setShowUpdateModal(false)}
                    
                  />
                  )} 
                  {
                    absence.status !== 'present' && (
                      <button
                        onClick={() => setShowUpdateModal(true)}
                        className="flex items-center justify-center rounded-full w-7 h-7 bg-lamaYellow text-white"
                      >
                        <Edit className="w-4 h-4" />
                        
                      </button>
                    )
                  }
                  
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      
      {/* Empty state */}
      {(!absences || absences.length === 0) && (
        <div className="p-8 text-center text-gray-500">
          No absence records found.
        </div>
      )}
    </div>
  );
};

export default AbsenceLogTable;