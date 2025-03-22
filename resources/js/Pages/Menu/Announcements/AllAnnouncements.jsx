import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { 
  CalendarIcon, 
  UsersIcon, 
  BellIcon, 
  AdjustmentsHorizontalIcon, 
  XMarkIcon
} from '@heroicons/react/24/outline';

/**
 * ViewAllAnnouncements Component
 * 
 * Displays all announcements with filtering capabilities
 * 
 * @param {Object[]} announcements - Array of announcement objects
 * @returns {JSX.Element} Rendered component
 */
const ViewAllAnnouncements = ({ announcements = [] }) => {
  const { auth } = usePage().props;
  const userRole = auth.user?.role || 'all';
  
  // State for filters
  const [filters, setFilters] = useState({
    visibility: 'all', // 'all', 'teacher', 'assistant', or 'mine'
    status: 'active',  // 'all', 'active', 'scheduled', 'expired'
    search: '',
  });
  
  const [showFilters, setShowFilters] = useState(false);
  
  // Format date to a user-friendly string
  const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    });
  };
  
  // Filter announcements based on current filters
  const filteredAnnouncements = React.useMemo(() => {
    if (!announcements?.length) return [];
    
    const now = new Date();
    
    return announcements.filter(announcement => {
      // Filter by visibility
      const visibilityMatch = 
        filters.visibility === 'all' ||
        (filters.visibility === 'mine' && announcement.visibility === userRole) ||
        filters.visibility === announcement.visibility;
      
      // Filter by status
      let statusMatch = true;
      const startDate = announcement.date_start ? new Date(announcement.date_start) : null;
      const endDate = announcement.date_end ? new Date(announcement.date_end) : null;
      
      if (filters.status === 'active') {
        statusMatch = (!startDate || startDate <= now) && (!endDate || endDate >= now);
      } else if (filters.status === 'scheduled') {
        statusMatch = startDate && startDate > now;
      } else if (filters.status === 'expired') {
        statusMatch = endDate && endDate < now;
      }
      
      // Filter by search term
      const searchMatch = 
        filters.search === '' ||
        announcement.title.toLowerCase().includes(filters.search.toLowerCase()) ||
        announcement.content.toLowerCase().includes(filters.search.toLowerCase());
      
      return visibilityMatch && statusMatch && searchMatch;
    }).sort((a, b) => new Date(b.date_announcement) - new Date(a.date_announcement));
  }, [announcements, filters, userRole]);
  
  // Get announcement status
  const getAnnouncementStatus = (announcement) => {
    const now = new Date();
    const startDate = announcement.date_start ? new Date(announcement.date_start) : null;
    const endDate = announcement.date_end ? new Date(announcement.date_end) : null;
    
    if (startDate && startDate > now) {
      return { label: 'Scheduled', className: 'bg-yellow-100 text-yellow-800' };
    } else if (endDate && endDate < now) {
      return { label: 'Expired', className: 'bg-gray-100 text-gray-800' };
    } else {
      return { label: 'Active', className: 'bg-green-100 text-green-800' };
    }
  };
  
  // Get visibility badge style
  const getVisibilityBadge = (visibility) => {
    const styles = {
      all: 'bg-green-100 text-green-800',
      teacher: 'bg-blue-100 text-blue-800',
      assistant: 'bg-purple-100 text-purple-800'
    };
    
    return {
      className: styles[visibility] || 'bg-gray-100 text-gray-800',
      label: visibility.charAt(0).toUpperCase() + visibility.slice(1)
    };
  };
  
  // Toggle filter panel
  const toggleFilters = () => {
    setShowFilters(!showFilters);
  };
  
  // Reset all filters
  const resetFilters = () => {
    setFilters({
      visibility: 'all',
      status: 'all',
      search: ''
    });
  };
  
  // Handle filter changes
  const handleFilterChange = (key, value) => {
    setFilters(prev => ({
      ...prev,
      [key]: value
    }));
  };
  
  return (
    <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
      <div className="p-4 border-b border-gray-200">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center space-x-2">
            <BellIcon className="h-5 w-5 text-gray-600" />
            <h1 className="text-xl font-semibold text-gray-800">All Announcements</h1>
          </div>
          
          <div className="flex items-center space-x-2">
            <div className="relative w-full sm:w-64">
              <input
                type="text"
                placeholder="Search announcements..."
                className="w-full border border-gray-300 rounded-md pl-3 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                value={filters.search}
                onChange={(e) => handleFilterChange('search', e.target.value)}
              />
              {filters.search && (
                <button 
                  className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                  onClick={() => handleFilterChange('search', '')}
                >
                  <XMarkIcon className="h-4 w-4" />
                </button>
              )}
            </div>
            
            <button 
              onClick={toggleFilters}
              className="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
              <AdjustmentsHorizontalIcon className="h-4 w-4 mr-1.5" />
              Filters
            </button>
          </div>
        </div>
        
        {/* Filters panel */}
        {showFilters && (
          <div className="mt-4 p-4 bg-gray-50 rounded-md border border-gray-200">
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-3">
              <h3 className="text-sm font-medium text-gray-700">Filter Announcements</h3>
              <button 
                onClick={resetFilters} 
                className="text-xs text-blue-600 hover:text-blue-800 mt-1 sm:mt-0"
              >
                Reset all filters
              </button>
            </div>
            
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Status</label>
                <select 
                  value={filters.status} 
                  onChange={(e) => handleFilterChange('status', e.target.value)}
                  className="w-full border border-gray-300 rounded-md py-1.5 pl-3 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="all">All Status</option>
                  <option value="active">Active</option>
                  <option value="scheduled">Scheduled</option>
                  <option value="expired">Expired</option>
                </select>
              </div>
              
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Visibility</label>
                <select 
                  value={filters.visibility} 
                  onChange={(e) => handleFilterChange('visibility', e.target.value)}
                  className="w-full border border-gray-300 rounded-md py-1.5 pl-3 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="all">All Visibility</option>
                  <option value="mine">Relevant to Me</option>
                  <option value="teacher">Teachers Only</option>
                  <option value="assistant">Assistants Only</option>
                </select>
              </div>
            </div>
          </div>
        )}
      </div>
      
      {/* Announcements list */}
      <div className="divide-y divide-gray-200">
        {filteredAnnouncements.length === 0 ? (
          <div className="text-center py-10">
            <BellIcon className="h-12 w-12 mx-auto text-gray-400" />
            <h3 className="mt-2 text-sm font-medium text-gray-900">No announcements found</h3>
            <p className="mt-1 text-sm text-gray-500">
              Adjust your filters or check back later for new announcements.
            </p>
          </div>
        ) : (
          filteredAnnouncements.map(announcement => {
            const status = getAnnouncementStatus(announcement);
            const visibility = getVisibilityBadge(announcement.visibility);
            
            return (
              <div key={announcement.id} className="p-4 hover:bg-gray-50 transition-colors duration-150">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between mb-2">
                  <h2 className="font-medium text-lg text-gray-800">{announcement.title}</h2>
                  
                  <div className="flex items-center space-x-2 mt-2 sm:mt-0">
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${status.className}`}>
                      {status.label}
                    </span>
                    
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${visibility.className}`}>
                      {visibility.label}
                    </span>
                  </div>
                </div>
                
                <div className="flex items-center text-xs text-gray-500 space-x-4 mb-3">
                  <span className="flex items-center">
                    <CalendarIcon className="h-3.5 w-3.5 mr-1" />
                    Posted: {formatDate(announcement.date_announcement)}
                  </span>
                  
                  <span className="flex items-center">
                    <CalendarIcon className="h-3.5 w-3.5 mr-1" />
                    Active: {formatDate(announcement.date_start)} - {formatDate(announcement.date_end)}
                  </span>
                </div>
                
                <div className="text-sm text-gray-600 leading-relaxed">
                  {announcement.content}
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
};

export default ViewAllAnnouncements;