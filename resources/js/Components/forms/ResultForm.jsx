import React, { useState, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import axios from 'axios';

const ResultForm = ({ type, data = {}, levels = [], classes = [], subjects = [], setOpen }) => {
  const { data: formData, setData, post, put, processing, errors, reset } = useForm({
    student_id: data?.student_id || '',
    subject_id: data?.subject_id || '',
    class_id: data?.class_id || '',
    grade1: data?.grade1 || '',
    grade2: data?.grade2 || '',
    grade3: data?.grade3 || '',
    notes: data?.notes || '',
    exam_date: data?.exam_date ? new Date(data.exam_date).toISOString().split('T')[0] : '',
  });

  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(false);

  // Fetch students when class changes
  useEffect(() => {
    if (formData.class_id) {
      setLoading(true);
      axios.get(`/results/students-by-class/${formData.class_id}`)
        .then(response => {
          setStudents(response.data);
          setLoading(false);
        })
        .catch(error => {
          console.error('Error fetching students:', error);
          setLoading(false);
        });
    } else {
      setStudents([]);
    }
  }, [formData.class_id]);

  const handleSubmit = (e) => {
    e.preventDefault();
    
    if (type === 'create') {
      post(route('results.store'), {
        onSuccess: () => {
          reset();
          setOpen(false);
        },
      });
    } else if (type === 'update') {
      put(route('results.update', data.id), {
        onSuccess: () => {
          reset();
          setOpen(false);
        },
      });
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <h2 className="text-xl font-semibold mb-4">
        {type === 'create' ? 'Add New Result' : 'Edit Result'}
      </h2>

      {/* Class Selection */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Class
        </label>
        <select
          value={formData.class_id}
          onChange={(e) => setData('class_id', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
          required
        >
          <option value="">Select Class</option>
          {classes.map((classe) => (
            <option key={classe.id} value={classe.id}>
              {classe.name} - {levels.find((level) => level.id === classe.level_id)?.name}
            </option>
          ))}
        </select>
        {errors.class_id && (
          <p className="text-red-500 text-xs mt-1">{errors.class_id}</p>
        )}
      </div>

      {/* Student Selection */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Student
        </label>
        <select
          value={formData.student_id}
          onChange={(e) => setData('student_id', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
          required
          disabled={!formData.class_id || loading}
        >
          <option value="">Select Student</option>
          {students.map((student) => (
            <option key={student.id} value={student.id}>
              {student.first_name} {student.last_name}
            </option>
          ))}
        </select>
        {loading && <p className="text-xs mt-1">Loading students...</p>}
        {errors.student_id && (
          <p className="text-red-500 text-xs mt-1">{errors.student_id}</p>
        )}
      </div>

      {/* Subject Selection */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Subject
        </label>
        <select
          value={formData.subject_id}
          onChange={(e) => setData('subject_id', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
          required
        >
          <option value="">Select Subject</option>
          {subjects.map((subject) => (
            <option key={subject.id} value={subject.id}>
              {subject.name}
            </option>
          ))}
        </select>
        {errors.subject_id && (
          <p className="text-red-500 text-xs mt-1">{errors.subject_id}</p>
        )}
      </div>

      {/* Grade 1 Input */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Grade 1 (/20)
        </label>
        <input
          type="text"
          value={formData.grade1}
          onChange={(e) => setData('grade1', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
          placeholder="../20"
          pattern="[0-9]+\/[0-9]+"
          title="Enter grade in format 15/20 or 8/10"
        />
        {errors.grade1 && (
          <p className="text-red-500 text-xs mt-1">{errors.grade1}</p>
        )}
      </div>

      {/* Grade 2 Input */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Grade 2 (/20)
        </label>
        <input
          type="text"
          value={formData.grade2}
          onChange={(e) => setData('grade2', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
          placeholder="../20"
          pattern="[0-9]+\/[0-9]+"
          title="Enter grade in format 15/20 or 8/10"
        />
        {errors.grade2 && (
          <p className="text-red-500 text-xs mt-1">{errors.grade2}</p>
        )}
      </div>

      {/* Grade 3 Input */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Grade 3 (/20)
        </label>
        <input
          type="text"
          value={formData.grade3}
          onChange={(e) => setData('grade3', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
          placeholder="../20"
          pattern="[0-9]+\/[0-9]+"
          title="Enter grade in format 15/20 or 8/10"
        />
        {errors.grade3 && (
          <p className="text-red-500 text-xs mt-1">{errors.grade3}</p>
        )}
      </div>

      {/* Exam Date Input */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Exam Date
        </label>
        <input
          type="date"
          value={formData.exam_date}
          onChange={(e) => setData('exam_date', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
        />
        {errors.exam_date && (
          <p className="text-red-500 text-xs mt-1">{errors.exam_date}</p>
        )}
      </div>

      {/* Notes Input */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Notes
        </label>
        <textarea
          value={formData.notes}
          onChange={(e) => setData('notes', e.target.value)}
          className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
          rows="3"
          placeholder="Any comments or notes about the student's performance"
        ></textarea>
        {errors.notes && (
          <p className="text-red-500 text-xs mt-1">{errors.notes}</p>
        )}
      </div>

      {/* Submit Button */}
      <div className="flex justify-end mt-6">
        <button
          type="button"
          onClick={() => setOpen(false)}
          className="mr-3 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-lamaPurple"
        >
          Cancel
        </button>
        <button
          type="submit"
          disabled={processing}
          className="px-4 py-2 text-sm font-medium text-white bg-lamaPurple border border-transparent rounded-md shadow-sm hover:bg-lamaPurpleDark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-lamaPurple"
        >
          {processing ? 'Saving...' : type === 'create' ? 'Add Result' : 'Update Result'}
        </button>
      </div>
    </form>
  );
};

export default ResultForm; 