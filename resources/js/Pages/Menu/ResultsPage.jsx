import DashboardLayout from '@/Layouts/DashboardLayout';
import React, { useState, useEffect, useRef } from 'react';

import FormModal from '@/Components/FormModal';
import axios from 'axios';
import { Filter, CheckCircle, XCircle } from 'lucide-react';


export default function ResultsPage({ teachers = [], classes = [], students = [], results = {}, levels, subjects, schools, role, teacherSubjectIds = [] }) {
  const [selectedTeacher, setSelectedTeacher] = useState('');
  const [selectedClass, setSelectedClass] = useState('');
  const [selectedSubject, setSelectedSubject] = useState('');
  const [availableClasses, setAvailableClasses] = useState(classes);
  const [availableStudents, setAvailableStudents] = useState(students);
  const [availableSubjects, setAvailableSubjects] = useState(subjects);
  const [classResults, setClassResults] = useState(results);
  const [loading, setLoading] = useState(false);
  const [editingCell, setEditingCell] = useState(null);
  const [editValue, setEditValue] = useState('');
  const [saving, setSaving] = useState(false);
  const inputRef = useRef(null);
  
  // Debug information
  console.log('Props received:', { 
    teachersCount: teachers?.length || 0, 
    classesCount: classes?.length || 0,
    studentsCount: students?.length || 0,
    resultsCount: Object.keys(results || {}).length,
    role,
    teacherSubjectIds
  });
  
  // For teachers, set their classes directly and filter subjects
  useEffect(() => {
    if (role === 'teacher' && classes && classes.length > 0) {
      console.log('Setting teacher classes:', classes);
      setAvailableClasses(classes);
      
      // Filter subjects to only those this teacher teaches
      if (teacherSubjectIds && teacherSubjectIds.length > 0) {
        const filteredSubjects = subjects.filter(subject => 
          teacherSubjectIds.includes(subject.id)
        );
        setAvailableSubjects(filteredSubjects);
      }
    }
  }, [role, classes, subjects, teacherSubjectIds]);

  // Fetch classes when teacher is selected (for admin and assistant)
  useEffect(() => {
    if (selectedTeacher && role !== 'teacher') {
      setLoading(true);
      console.log('Fetching classes for teacher:', selectedTeacher);
      
      axios.get(`/results/classes-by-teacher/${selectedTeacher}`)
        .then(response => {
          console.log('Classes fetched:', response.data);
          setAvailableClasses(response.data);
          setLoading(false);
        })
        .catch(error => {
          console.error('Error fetching classes:', error);
          setLoading(false);
        });
    }
    setSelectedClass('');
    setSelectedSubject('');
    setAvailableStudents([]);
    setClassResults({});
  }, [selectedTeacher, role]);

  // Fetch students, subjects, and results when class is selected
  useEffect(() => {
    if (selectedClass) {
      setLoading(true);
      console.log('Fetching students and results for class:', selectedClass);
      
      const promises = [
        axios.get(`/results/students-by-class/${selectedClass}`),
        axios.get(`/results/by-class/${selectedClass}`)
      ];
      
      // If not a teacher, we need to fetch subjects for this teacher and class
      if (role !== 'teacher' && selectedTeacher) {
        promises.push(axios.get(`/results/subjects-by-teacher/${selectedTeacher}/${selectedClass}`));
      }
      
      Promise.all(promises)
        .then(responses => {
          const [studentsResponse, resultsResponse, subjectsResponse] = responses;
          
          console.log('Students fetched:', studentsResponse.data);
          console.log('Results fetched:', resultsResponse.data);
          
          // Check if we received valid data
          if (!Array.isArray(studentsResponse.data)) {
            console.error('Received invalid students data:', studentsResponse.data);
            setAvailableStudents([]);
          } else {
            setAvailableStudents(studentsResponse.data);
          }
          
          // Check if we received valid results data
          if (typeof resultsResponse.data !== 'object') {
            console.error('Received invalid results data:', resultsResponse.data);
            setClassResults({});
          } else {
            setClassResults(resultsResponse.data);
          }
          
          // Update available subjects if we got a response
          if (subjectsResponse && Array.isArray(subjectsResponse.data)) {
            console.log('Subjects fetched:', subjectsResponse.data);
            setAvailableSubjects(subjectsResponse.data);
          } else if (role !== 'teacher') {
            // For admin/assistant without specific subjects, show all
            setAvailableSubjects(subjects);
          }
          
          setLoading(false);
        })
        .catch(error => {
          console.error('Error fetching data:', error);
          setAvailableStudents([]);
          setClassResults({});
          setLoading(false);
        });
    } else {
      setAvailableStudents([]);
      setClassResults({});
      setSelectedSubject('');
    }
  }, [selectedClass, selectedTeacher, role]);

  // Focus input when editing
  useEffect(() => {
    if (editingCell && inputRef.current) {
      inputRef.current.focus();
    }
  }, [editingCell]);

  const getGradeColor = (grade) => {
    if (!grade) return 'text-gray-400';
    
    // Check if it's a fraction format (e.g., "14/20")
    if (grade.includes('/')) {
      const [numerator, denominator] = grade.split('/').map(part => parseFloat(part.trim()));
      const percentage = (numerator / denominator) * 100;
      
      if (percentage >= 85) return 'text-green-600';
      if (percentage >= 70) return 'text-blue-600';
      if (percentage >= 50) return 'text-yellow-600';
      if (percentage >= 40) return 'text-orange-600';
      return 'text-red-600';
    }
    
    // Default color
    return 'text-gray-700';
  };

  const handleCellClick = (studentId, subjectId, field, value) => {
    if (role !== 'admin' && role !== 'teacher') return; // Only admin and teachers can edit
    
    setEditingCell({ studentId, subjectId, field });
    setEditValue(value || '');
  };

  const handleCellBlur = (e) => {
    // Add a small delay to allow button clicks to complete
    setTimeout(() => {
      // Check if we still have an editingCell (it might have been cleared by a button action)
      if (editingCell) {
        cancelEdit();
      }
    }, 100);
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter') {
      saveEdit();
    } else if (e.key === 'Escape') {
      cancelEdit();
    }
  };

  const saveEdit = () => {
    if (!editingCell) return;
    
    const { studentId, subjectId, field } = editingCell;
    setSaving(true);
    
    // Log what we're trying to update
    console.log('Attempting to update grade with data:', {
      student_id: studentId,
      subject_id: subjectId,
      class_id: selectedClass,
      grade_field: field,
      value: editValue,
    });
    
    // Axios will automatically use the CSRF token from the cookies
    // when axios.defaults.withCredentials = true is set in bootstrap.js
    axios.post('/results/update-grade', {
      student_id: studentId,
      subject_id: subjectId,
      class_id: selectedClass,
      grade_field: field,
      value: editValue,
    })
      .then(response => {
        console.log('Update successful! Server response:', response.data);
        
        // Check if we got a success response
        if (!response.data.success) {
          console.error('Server reported failure:', response.data.message);
          alert(`Failed to save grade: ${response.data.message}`);
          setSaving(false);
          return;
        }
        
        // Update the local state with the new result
        const newResults = { ...classResults };
        
        // If this student doesn't have results for this subject yet, initialize an array
        if (!newResults[studentId]) {
          newResults[studentId] = [];
        }
        
        // Find existing result for this subject
        const existingResultIndex = newResults[studentId].findIndex(r => r.subject_id === subjectId);
        
        if (existingResultIndex >= 0) {
          // Update existing result
          newResults[studentId][existingResultIndex][field] = editValue;
          newResults[studentId][existingResultIndex].final_grade = response.data.final_grade;
          console.log('Updated existing result:', newResults[studentId][existingResultIndex]);
        } else {
          // Add new result
          const newResult = {
            student_id: studentId,
            subject_id: subjectId,
            class_id: selectedClass,
            [field]: editValue,
            final_grade: response.data.final_grade,
          };
          newResults[studentId].push(newResult);
          console.log('Added new result:', newResult);
        }
        
        setClassResults(newResults);
        setEditingCell(null);
        setEditValue('');
        setSaving(false);
      })
      .catch(error => {
        console.error('Error saving grade:', error);
        
        let errorMessage = 'An unknown error occurred';
        
        if (error.response) {
          // The request was made and the server responded with a status code
          // that falls out of the range of 2xx
          console.error('Error response data:', error.response.data);
          console.error('Error response status:', error.response.status);
          console.error('Error response headers:', error.response.headers);
          errorMessage = error.response?.data?.message || `Server error: ${error.response.status}`;
        } else if (error.request) {
          // The request was made but no response was received
          console.error('Error request:', error.request);
          errorMessage = 'No response from server. Please check your connection.';
        } else {
          // Something happened in setting up the request that triggered an Error
          console.error('Error message:', error.message);
          errorMessage = error.message || 'Request setup error';
        }
        
        setSaving(false);
        
        // Show a more detailed alert to the user
        alert(`Failed to save grade: ${errorMessage}`);
      });
  };

  const cancelEdit = () => {
    setEditingCell(null);
    setEditValue('');
  };

  const renderGradeCell = (studentId, subjectId, field, placeholder, value) => {
    const isEditing = editingCell && 
                    editingCell.studentId === studentId && 
                    editingCell.subjectId === subjectId && 
                    editingCell.field === field;
    
    // Determine the correct placeholder based on the field
    let cellPlaceholder = placeholder;
    if (field === 'grade1' || field === 'grade2' || field === 'grade3') {
      cellPlaceholder = '../20';
    }
    
    return (
      <div 
        className={`px-3 py-2 cursor-pointer ${isEditing ? 'bg-gray-100' : 'hover:bg-gray-50'}`}
        onClick={() => !isEditing && handleCellClick(studentId, subjectId, field, value)}
      >
        {isEditing ? (
          <div className="flex items-center">
            <input
              ref={inputRef}
              type="text"
              className="w-20 px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-lamaPurple"
              value={editValue}
              onChange={(e) => setEditValue(e.target.value)}
              onBlur={handleCellBlur}
              onKeyDown={handleKeyDown}
              placeholder={cellPlaceholder}
              pattern={field !== 'notes' ? "[0-9]+\/[0-9]+" : ".*"}
              title={field !== 'notes' ? "Enter grade in format 15/20 or 8/10" : ""}
            />
            <div className="flex items-center ml-1">
              {saving ? (
                <span className="text-xs text-gray-400">Saving...</span>
              ) : (
                <>
                  <button 
                    onClick={(e) => {
                      e.preventDefault();
                      e.stopPropagation();
                      saveEdit();
                    }} 
                    className="text-green-500 hover:text-green-600 p-0.5"
                  >
                    <CheckCircle size={16} />
                  </button>
                  <button 
                    onClick={(e) => {
                      e.preventDefault();
                      e.stopPropagation();
                      cancelEdit();
                    }} 
                    className="text-red-500 hover:text-red-600 p-0.5"
                  >
                    <XCircle size={16} />
                  </button>
                </>
              )}
            </div>
          </div>
        ) : (
          <div className="flex items-center">
            <span className={`text-sm font-medium ${field !== 'notes' ? getGradeColor(value) : 'text-gray-700'}`}>
              {value || '—'}
            </span>
          </div>
        )}
      </div>
    );
  };

  // Filter the subjects based on selection or role
  const getFilteredSubjects = () => {
    if (role === 'teacher') {
      // For teachers, only show subjects they teach
      return availableSubjects;
    } else if (selectedSubject) {
      // If a subject is selected, only show that subject
      return availableSubjects.filter(subject => subject.id === parseInt(selectedSubject));
    }
    return availableSubjects;
  };

  return (
    <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0">
      {/* TOP */}
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-lg font-semibold">Student Results</h1>
        <div className="flex items-center gap-4">
          <button 
            className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow"
          >
            <Filter className="w-4 h-4 text-white" />
          </button>
          {role === "admin" && <FormModal table="result" type="create" levels={levels} classes={availableClasses} subjects={subjects} />}
        </div>
      </div>

      {/* FILTERS */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        {/* Teacher Selection */}
        {role !== 'teacher' && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Select Teacher
            </label>
            <select
              value={selectedTeacher}
              onChange={(e) => setSelectedTeacher(e.target.value)}
              className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
            >
              <option value="">Select a teacher</option>
              {teachers && teachers.map((teacher) => (
                <option key={teacher.id} value={teacher.id}>
                  {teacher.first_name} {teacher.last_name}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Class Selection */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Select Class
          </label>
          <select
            value={selectedClass}
            onChange={(e) => setSelectedClass(e.target.value)}
            className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
            disabled={!selectedTeacher && role !== 'teacher'}
          >
            <option value="">Select a class</option>
            {availableClasses && availableClasses.map((class_) => (
              <option key={class_.id} value={class_.id}>
                {class_.name}
              </option>
            ))}
          </select>
        </div>

        {/* Subject Filter (only for admin and assistant) */}
        {(role === 'admin' || role === 'assistant') && (
    <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Filter by Subject
            </label>
            <select
              value={selectedSubject}
              onChange={(e) => setSelectedSubject(e.target.value)}
              className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
              disabled={!selectedClass}
            >
              <option value="">All Subjects</option>
              {availableSubjects && availableSubjects.map((subject) => (
                <option key={subject.id} value={subject.id}>
                  {subject.name}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

      {/* RESULTS TABLE */}
      {loading ? (
        <div className="text-center py-4">Loading...</div>
      ) : selectedClass && availableStudents && availableStudents.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 border-collapse">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Student Name
                </th>
                {getFilteredSubjects().map((subject) => (
                  <th key={subject.id} className="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <div className="flex flex-col">
                      <span className="mb-2">{subject.name}</span>
                      <div className="grid grid-cols-4 gap-2 text-xxs">
                        <span className="font-normal">Grade 1 (/20)</span>
                        <span className="font-normal">Grade 2 (/20)</span>
                        <span className="font-normal">Grade 3 (/20)</span>
                        <span className="font-normal">Notes</span>
                      </div>
                    </div>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {availableStudents.map((student) => (
                <tr key={student.id} className="hover:bg-gray-50">
                  <td className="px-6 py-2 whitespace-nowrap">
                    <div className="text-sm font-medium text-gray-900">
                      {student.first_name} {student.last_name}
                    </div>
                  </td>
                  {getFilteredSubjects().map((subject) => {
                    const studentResults = classResults[student.id] || [];
                    const result = studentResults.find(r => r.subject_id === subject.id);
                    return (
                      <td key={subject.id} className="p-0 border-l border-gray-200">
                        <div className="grid grid-cols-4 divide-x divide-gray-200">
                          {/* Grade 1 */}
                          {renderGradeCell(
                            student.id, 
                            subject.id, 
                            'grade1',
                            '../20',
                            result?.grade1
                          )}
                          
                          {/* Grade 2 */}
                          {renderGradeCell(
                            student.id, 
                            subject.id, 
                            'grade2',
                            '../20',
                            result?.grade2
                          )}
                          
                          {/* Grade 3 */}
                          {renderGradeCell(
                            student.id, 
                            subject.id, 
                            'grade3',
                            '../20',
                            result?.grade3
                          )}
                          
                          {/* Notes */}
                          {renderGradeCell(
                            student.id, 
                            subject.id, 
                            'notes',
                            'Notes',
                            result?.notes
                          )}
                        </div>
                        {result?.final_grade && (
                          <div className="text-center py-1 border-t border-gray-200 bg-gray-50">
                            <span className={`text-xs font-bold ${getGradeColor(result.final_grade)}`}>
                              Final: {result.final_grade}
                            </span>
                          </div>
                        )}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="text-center py-4 text-gray-500">
          {!selectedClass ? 'Please select a class to view results' : 'No students found in this class'}
        </div>
      )}
    </div>
  );
}

ResultsPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;