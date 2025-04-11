import { useState, useEffect } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { User, BookOpen, School, Mail, Phone, AlertCircle, CheckCircle } from 'lucide-react';

export default function TeacherClassIndex({ teachers, classes, auth, flash }) {
    const [selectedTeacher, setSelectedTeacher] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [filteredTeachers, setFilteredTeachers] = useState(teachers);
    const [showConfirmModal, setShowConfirmModal] = useState(false);
    const [deleteData, setDeleteData] = useState({ teacherId: null, classId: null, teacherName: '', className: '' });
    const [toast, setToast] = useState({ 
        show: flash?.success || flash?.error ? true : false, 
        message: flash?.success || flash?.error || '', 
        type: flash?.success ? 'success' : flash?.error ? 'error' : ''
    });
    
    // Process flash messages from server
    useEffect(() => {
        if (flash?.success || flash?.error) {
            setToast({
                show: true,
                message: flash?.success || flash?.error,
                type: flash?.success ? 'success' : 'error'
            });
            
            // Auto-hide toast after 3 seconds
            const timer = setTimeout(() => {
                setToast({ show: false, message: '', type: '' });
            }, 3000);
            
            return () => clearTimeout(timer);
        }
    }, [flash]);
    
    // Filter teachers when search term changes
    useEffect(() => {
        if (searchTerm.trim() === '') {
            setFilteredTeachers(teachers);
        } else {
            const term = searchTerm.toLowerCase();
            setFilteredTeachers(
                teachers.filter(
                    (teacher) => 
                        `${teacher.first_name} ${teacher.last_name}`.toLowerCase().includes(term) || 
                        teacher.email.toLowerCase().includes(term) ||
                        (teacher.schools && teacher.schools.some(school => 
                            school.name && school.name.toLowerCase().includes(term)
                        )) ||
                        (teacher.subjects && teacher.subjects.some(subject => 
                            subject.name && subject.name.toLowerCase().includes(term)
                        ))
                )
            );
        }
    }, [searchTerm, teachers]);
    
    // Get classes for a specific teacher
    const getTeacherClasses = (teacher) => {
        return teacher.classes || [];
    };
    
    // Get teachers for a specific class
    const getClassTeachers = (classId) => {
        return teachers.filter(teacher => 
            teacher.classes && teacher.classes.some(cls => cls.id === classId)
        );
    };
    
    // Handle teacher selection
    const handleTeacherSelect = (teacher) => {
        setSelectedTeacher(teacher === selectedTeacher ? null : teacher);
    };
    
    // Handle deletion confirmation
    const confirmDelete = (teacherId, classId, teacherName, className) => {
        setDeleteData({
            teacherId,
            classId,
            teacherName,
            className
        });
        setShowConfirmModal(true);
    };
    
    // Execute deletion
    const executeDelete = () => {
        router.post(route('teacher-classes.remove', {
            teacher_id: deleteData.teacherId,
            class_id: deleteData.classId,
        }), {}, {
            preserveScroll: true,
            // Let Inertia handle the server response properly
            onSuccess: () => {
                setShowConfirmModal(false);
                // The server flash message will be handled by our flash effect
            },
            onError: (errors) => {
                console.error("Error removing teacher from class:", errors);
                setShowConfirmModal(false);
                setToast({
                    show: true,
                    message: errors.message || 'Failed to remove teacher from class',
                    type: 'error'
                });
                
                // Auto-hide toast after 3 seconds
                setTimeout(() => {
                    setToast({ show: false, message: '', type: '' });
                }, 3000);
            }
        });
    };
    
    // Cancel deletion
    const cancelDelete = () => {
        setShowConfirmModal(false);
        setDeleteData({ teacherId: null, classId: null, teacherName: '', className: '' });
    };
    
    return (
        <>
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <div className="flex justify-between items-center mb-6">
                                <div>
                                    <h2 className="text-xl font-semibold">Teacher-Class Assignments</h2>
                                    <p className="text-sm text-gray-500 mt-1">
                                        Manage which teachers are assigned to which classes. 
                                        <span className="font-medium text-lamaPurple"> Teacher details are shown inline without requiring single pages.</span>
                                    </p>
                                </div>
                                <Link
                                    href={route('teacher-classes.create')}
                                    className="px-4 py-2 bg-lamaPurple text-white rounded-md hover:bg-lamaPurple/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-lamaPurple"
                                >
                                    Assign Teachers
                                </Link>
                            </div>
                            
                            {/* Search Bar */}
                            <div className="mb-6">
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <User className="h-5 w-5 text-gray-400" />
                                    </div>
                                    <input
                                        type="text"
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        placeholder="Search teachers by name, email, subject, or school"
                                        className="w-full pl-10 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-lamaPurple focus:border-lamaPurple"
                                    />
                                </div>
                            </div>
                            
                            {/* Teachers List */}
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                {filteredTeachers.map((teacher) => {
                                    const teacherClasses = getTeacherClasses(teacher);
                                    const isSelected = selectedTeacher?.id === teacher.id;
                                    
                                    return (
                                        <div 
                                            key={teacher.id}
                                            className={`border rounded-lg overflow-hidden hover:shadow-md transition-shadow ${
                                                isSelected ? 'ring-2 ring-lamaPurple' : ''
                                            }`}
                                            onClick={() => handleTeacherSelect(teacher)}
                                        >
                                            {/* Teacher Header with Profile Picture */}
                                            <div className="bg-gray-50 p-4 flex justify-between items-center cursor-pointer border-b">
                                                <div className="flex items-center gap-3">
                                                    <div className="h-12 w-12 rounded-full overflow-hidden bg-gray-200 flex-shrink-0">
                                                        <img 
                                                            src={teacher.profile_image || "/teacherPrfile2.png"} 
                                                            alt={`${teacher.first_name} ${teacher.last_name}`}
                                                            className="h-full w-full object-cover"
                                                        />
                                                    </div>
                                                    <div>
                                                        <h3 className="font-medium">{teacher.first_name} {teacher.last_name}</h3>
                                                        <div className="flex items-center text-sm text-gray-500">
                                                            <Mail className="h-3 w-3 mr-1" />
                                                            <span className="truncate max-w-[150px]">{teacher.email}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex flex-col items-end space-y-1">
                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                                                        teacher.status === 'active' 
                                                            ? 'bg-green-100 text-green-800' 
                                                            : 'bg-red-100 text-red-800'
                                                    }`}>
                                                        {teacher.status || 'unknown'}
                                                    </span>
                                                    <div className="bg-lamaSkyLight text-lamaSky text-xs font-medium px-2 py-1 rounded">
                                                        {teacherClasses.length} Classes
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            {/* Teacher Contact Info */}
                                            <div className="px-4 py-2 border-b">
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center gap-1 text-gray-600">
                                                        <Phone className="h-3 w-3" />
                                                        <span className="text-sm">{teacher.phone_number || 'No phone'}</span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            {/* School */}
                                            {teacher.schools && teacher.schools.length > 0 && (
                                                <div className="px-4 py-2 border-b">
                                                    <div className="flex justify-between items-center">
                                                        <div className="flex items-center gap-1">
                                                            <School className="h-4 w-4 text-gray-500" />
                                                            <p className="text-xs text-gray-500">Schools</p>
                                                        </div>
                                                        <div className="flex flex-wrap gap-1 justify-end">
                                                            {teacher.schools.map((school) => (
                                                                <span key={school.id} className="text-xs bg-gray-100 px-2 py-1 rounded">
                                                                    {school.name}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                            
                                            {/* Subjects */}
                                            {teacher.subjects && teacher.subjects.length > 0 && (
                                                <div className="px-4 py-2 border-b">
                                                    <div className="flex justify-between items-center">
                                                        <div className="flex items-center gap-1">
                                                            <BookOpen className="h-4 w-4 text-gray-500" />
                                                            <p className="text-xs text-gray-500">Subjects</p>
                                                        </div>
                                                        <div className="flex flex-wrap gap-1 justify-end">
                                                            {teacher.subjects.map((subject) => (
                                                                <span key={subject.id} className="text-xs bg-lamaPurpleLight text-lamaPurple px-2 py-1 rounded">
                                                                    {subject.name}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                            
                                            {/* Classes */}
                                            <div className="px-4 py-2">
                                                <p className="text-xs text-gray-500 mb-2 flex items-center gap-1">
                                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                                    </svg>
                                                    {teacherClasses.length > 0 ? 'Assigned Classes' : 'No Classes Assigned'}
                                                </p>
                                                <div className="space-y-2">
                                                    {teacherClasses.map((cls) => (
                                                        <div key={cls.id} className="flex items-center justify-between bg-gray-50 px-3 py-2 rounded border border-gray-100">
                                                            <span className="text-sm font-medium">{cls.name}</span>
                                                            <button 
                                                                type="button"
                                                                className="text-xs px-2 py-1 bg-red-50 text-red-600 rounded hover:bg-red-100 transition-colors"
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    confirmDelete(
                                                                        teacher.id, 
                                                                        cls.id, 
                                                                        `${teacher.first_name} ${teacher.last_name}`,
                                                                        cls.name
                                                                    );
                                                                }}
                                                            >
                                                                Remove
                                                            </button>
                                                        </div>
                                                    ))}
                                                    {teacherClasses.length === 0 && (
                                                        <div className="text-center py-3 bg-gray-50 rounded text-xs text-gray-500 border border-dashed border-gray-200">
                                                            This teacher is not assigned to any classes.
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            
                            {filteredTeachers.length === 0 && (
                                <div className="text-center py-12">
                                    <p className="text-gray-500">No teachers found matching your search.</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* Confirmation Modal */}
            {showConfirmModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-lg shadow-lg max-w-md w-full">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">Confirm Removal</h3>
                            <p className="text-sm text-gray-600 mb-6">
                                Are you sure you want to remove <span className="font-medium">{deleteData.teacherName}</span> from the class <span className="font-medium">{deleteData.className}</span>?
                                This action cannot be undone.
                            </p>
                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={cancelDelete}
                                    className="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={executeDelete}
                                    className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            
            {/* Toast Notification */}
            {toast.show && (
                <div className="fixed bottom-4 right-4 z-50">
                    <div className={`flex items-center p-4 rounded-lg shadow-lg ${
                        toast.type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                    }`}>
                        {toast.type === 'success' ? (
                            <CheckCircle className="h-5 w-5 mr-3" />
                        ) : (
                            <AlertCircle className="h-5 w-5 mr-3" />
                        )}
                        <p>{toast.message}</p>
                    </div>
                </div>
            )}
        </>
    );
}

TeacherClassIndex.layout = page => <DashboardLayout children={page} />; 