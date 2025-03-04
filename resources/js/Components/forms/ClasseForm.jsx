import DashboardLayout from '@/Layouts/DashboardLayout';
import React from 'react';
import { useForm } from 'react-hook-form';

const ClasseForm = ({ onSubmit }) => {
  const { register, handleSubmit, formState: { errors } } = useForm();

  const levels = [
    { id: 1, name: "BAC" },
    { id: 2, name: "2BAC" }
  ];

  const handleFormSubmit = (formData) => {
    console.log(formData);
    if (onSubmit) onSubmit(formData);
  };

  return (
    <form className="flex flex-col gap-8" onSubmit={handleSubmit(handleFormSubmit)}>
      <h1 className="text-xl font-semibold">Create a New Class (Group)</h1>

      <div className="flex flex-wrap gap-4">
        <div className="flex flex-col gap-2 w-full md:w-1/2">
          <label className="text-xs text-gray-500">Class Name</label>
          <input
            type="text"
            {...register('class_name', { required: 'Class name is required' })}
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            placeholder="e.g., 2bac svt"
          />
          {errors.class_name && <p className="text-xs text-red-400">{errors.class_name.message}</p>}
        </div>

        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-500">Group Name</label>
          <input
            type="text"
            {...register('group_name', { required: 'Group name is required' })}
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            placeholder="e.g., G1"
          />
          {errors.group_name && <p className="text-xs text-red-400">{errors.group_name.message}</p>}
        </div>
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-500">Level</label>
          <select
            {...register('level_id', { required: 'Level is required' })}
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
          >
            <option value="">Select Level</option>
            {levels.map((level) => (
              <option key={level.id} value={level.id}>{level.name}</option>
            ))}
          </select>
          {errors.level_id && <p className="text-xs text-red-400">{errors.level_id.message}</p>}
        </div>
      </div>

      <button
        type="submit"
        className="bg-blue-500 text-white p-2 rounded-md"
      >
        Create Class
      </button>
    </form>
  );
};
ClasseForm.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default ClasseForm;
