import React, { useState } from 'react';
import { useForm } from 'react-hook-form';

const OfferForm = ({ type, data }) => {
  const { register, handleSubmit, setValue, formState: { errors } } = useForm({
    defaultValues: data || {}
  });

  const predefinedSubjects = ["Math", "Physics", "Chemistry", "Biology"];
  const [checkedSubjects, setCheckedSubjects] = useState([]);
  const [percentages, setPercentages] = useState({});

  const onSubmit = (formData) => {
    const formDataWithSubjectsAndPercentage = {
      ...formData,
      subjects: predefinedSubjects.filter(subject => checkedSubjects.includes(subject)),
      percentage: percentages
    };
    console.log(formDataWithSubjectsAndPercentage);
  };

  const handleCheckboxChange = (subject) => {
    if (checkedSubjects.includes(subject)) {
      setCheckedSubjects(checkedSubjects.filter(item => item !== subject));
      setPercentages(prev => {
        const newPercentages = { ...prev };
        delete newPercentages[subject];
        return newPercentages;
      });
    } else {
      setCheckedSubjects([...checkedSubjects, subject]);
    }
  };

  const handlePercentageChange = (subject, value) => {
    setPercentages({
      ...percentages,
      [subject]: value
    });
  };

  return (
    <form className="flex flex-col gap-8" onSubmit={handleSubmit(onSubmit)}>
      <h1 className="text-xl font-semibold">{type === "create" ? "Create a New Offer" : "Update Offer"}</h1>
      
      <div className="flex flex-wrap gap-4">
        <div className="flex flex-col gap-2 w-full md:w-1/2">
          <label className="text-xs text-gray-500">Offer Name</label>
          <input
            type="text"
            {...register('offer_name', { required: 'Offer name is required' })}
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
          />
          {errors.offer_name && <p className="text-xs text-red-400">{errors.offer_name.message}</p>}
        </div>

        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-500">Price</label>
          <input
            type="number"
            {...register('price', { required: 'Price is required' })}
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
          />
          {errors.price && <p className="text-xs text-red-400">{errors.price.message}</p>}
        </div>
      </div>

      <div>
        <label className="text-xs text-gray-500">Subjects and Percentages</label>
        {predefinedSubjects.map((subject, index) => (
          <div key={index} className="flex items-center gap-4 mb-2">
            <input
              type="text"
              value={subject}
              disabled={true}
              className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-1/2"
              placeholder="Subject Name"
            />

            <input
              type="checkbox"
              checked={checkedSubjects.includes(subject)}
              onChange={() => handleCheckboxChange(subject)}
              className="w-4 h-4"
            />
            
            {checkedSubjects.includes(subject) && (
              <input
                type="number"
                value={percentages[subject] || ''}
                onChange={(e) => handlePercentageChange(subject, e.target.value)}
                placeholder="Percentage"
                className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-1/4"
              />
            )}
          </div>
        ))}
      </div>

      <div className="flex flex-col gap-2 w-full md:w-1/4">
        <label className="text-xs text-gray-500">Level</label>
        <select
          {...register('level_id', { required: 'Level is required' })}
          className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
        >
          <option value="">Select Level</option>
          <option value="1">Level 1</option>
          <option value="2">Level 2</option>
        </select>
        {errors.level_id && <p className="text-xs text-red-400">{errors.level_id.message}</p>}
      </div>

      <button
        type="submit"
        className="bg-blue-500 text-white p-2 rounded-md"
      >
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
  );
};

export default OfferForm;
