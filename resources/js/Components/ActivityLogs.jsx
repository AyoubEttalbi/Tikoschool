import React, { useState } from 'react';
import Pagination from '@/Components/Pagination';
import { ChevronDown, ChevronUp, Clock, User, Database, Target, Activity } from 'lucide-react';

const ActivityLogs = ({ logs = { data: [], links: [] } }) => {
  const [expandedLogs, setExpandedLogs] = useState({});
  
  // Toggle visibility of log details
  const toggleLogDetails = (logId) => {
    setExpandedLogs((prev) => ({
      ...prev,
      [logId]: !prev[logId],
    }));
  };

  // Format date to a more readable format
  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    }).format(date);
  };

  // Get action badge color based on action type
  const getActionBadgeClass = (action) => {
    switch (action) {
      case 'created':
        return 'bg-green-100 text-green-800';
      case 'updated':
        return 'bg-blue-100 text-blue-800';
      case 'deleted':
        return 'bg-red-100 text-red-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  return (
    <div className="bg-white p-6 rounded-lg shadow-sm">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-xl font-semibold text-gray-800">Activity Logs</h2>
        <span className="text-sm text-gray-500">{logs.data.length} activities</span>
      </div>
      
      {logs.data && logs.data.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                <th className="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                <th className="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Table</th>
                <th className="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Target</th>
                <th className="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                <th className="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                <th className="p-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {logs.data.map((log) => (
                <tr key={log.id} className="hover:bg-gray-50 transition-colors">
                  <td className="p-4">
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${getActionBadgeClass(log.properties?.action)}`}>
                      {log.properties?.action || 'Unknown'}
                    </span>
                  </td>
                  <td className="p-4 text-sm text-gray-700">
                    <div className="flex items-center">
                      <Database className="w-4 h-4 mr-2 text-gray-400" />
                      {log.properties?.table || 'N/A'}
                    </div>
                  </td>
                  <td className="p-4 text-sm text-gray-700">
                    <div className="flex items-center">
                      <Target className="w-4 h-4 mr-2 text-gray-400" />
                      {log.properties?.TargetName || 'N/A'}
                    </div>
                  </td>
                  <td className="p-4 text-sm text-gray-700">
                    <div className="flex items-center">
                      <User className="w-4 h-4 mr-2 text-gray-400" />
                      {log.properties?.user || 'N/A'}
                    </div>
                  </td>
                  <td className="p-4 text-sm text-gray-700">
                    <div className="flex items-center">
                      <Clock className="w-4 h-4 mr-2 text-gray-400" />
                      {formatDate(log.created_at)}
                    </div>
                  </td>
                  <td className="p-4">
                    <button
                      onClick={() => toggleLogDetails(log.id)}
                      className="flex items-center text-blue-600 hover:text-blue-800 text-sm font-medium"
                    >
                      {expandedLogs[log.id] ? (
                        <>
                          <ChevronUp className="w-4 h-4 mr-1" />
                          Hide Details
                        </>
                      ) : (
                        <>
                          <ChevronDown className="w-4 h-4 mr-1" />
                          Show Details
                        </>
                      )}
                    </button>
                    {expandedLogs[log.id] && (
                      <div className="mt-3 p-3 bg-gray-50 rounded-md text-xs">
                        {log.properties?.action === 'updated' ? (
                          <>
                            <div className="flex items-center mb-2">
                              <Activity className="w-4 h-4 mr-2 text-blue-500" />
                              <span className="font-medium">Changed Fields:</span>
                            </div>
                            <div className="pl-6 space-y-1">
                              {Object.entries(log.properties?.changed_fields || {}).map(([key, value]) => {
                                // Skip if old and new values are the same (e.g., 1 → "1")
                                if (String(value.old) === String(value.new)) {
                                  return null;
                                }
                                return (
                                  <div key={key} className="flex items-start">
                                    <span className="font-medium min-w-[100px]">{key}:</span>
                                    <span className="ml-2">
                                      <span className="text-red-500">{JSON.stringify(value.old)}</span>
                                      <span className="mx-2">→</span>
                                      <span className="text-green-500">{JSON.stringify(value.new)}</span>
                                    </span>
                                  </div>
                                );
                              })}
                            </div>
                          </>
                        ) : log.properties?.action === 'created' ? (
                          <>
                            <div className="flex items-center mb-2">
                              <Activity className="w-4 h-4 mr-2 text-green-500" />
                              <span className="font-medium">New Data:</span>
                            </div>
                            <div className="pl-6 space-y-1">
                              {Object.entries(log.properties?.new_data || {}).map(([key, value]) => (
                                <div key={key} className="flex items-start">
                                  <span className="font-medium min-w-[100px]">{key}:</span>
                                  <span className="ml-2 text-green-500">{JSON.stringify(value)}</span>
                                </div>
                              ))}
                            </div>
                          </>
                        ) : log.properties?.action === 'deleted' ? (
                          <>
                            <div className="flex items-center mb-2">
                              <Activity className="w-4 h-4 mr-2 text-red-500" />
                              <span className="font-medium">Deleted Data:</span>
                            </div>
                            <div className="pl-6 space-y-1">
                              {Object.entries(log.properties?.deleted_data || {}).map(([key, value]) => (
                                <div key={key} className="flex items-start">
                                  <span className="font-medium min-w-[100px]">{key}:</span>
                                  <span className="ml-2 text-red-500">{JSON.stringify(value)}</span>
                                </div>
                              ))}
                            </div>
                          </>
                        ) : null}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="text-center py-8">
          <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
            <Activity className="w-8 h-8 text-gray-400" />
          </div>
          <p className="text-gray-500">No activity logs found.</p>
        </div>
      )}
      
      {logs.links && logs.links.length > 0 && (
        <div className="mt-6">
          <Pagination links={logs.links} />
        </div>
      )}
    </div>
  );
};

export default ActivityLogs; 