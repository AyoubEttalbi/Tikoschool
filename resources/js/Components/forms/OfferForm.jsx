import { router } from '@inertiajs/react';
import React, { useState, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import Confetti from 'react-confetti';
import { Tag, DollarSign, Book, Layers, Check, X } from 'lucide-react'; // Import icons

const OfferForm = ({ type, data, setOpen, subjects, levels }) => {
  const { register, handleSubmit, setValue, formState: { errors } } = useForm({
    defaultValues: data || {}
  });

  const predefinedSubjects = subjects.map(subject => subject.name);
  const [checkedSubjects, setCheckedSubjects] = useState(data?.subjects || []);
  const [percentages, setPercentages] = useState(data?.percentage || {});
  const [showConfetti, setShowConfetti] = useState(false);

  useEffect(() => {
    if (type === "update" && data) {
      setValue('offer_name', data.offer_name);
      setValue('price', data.price);
      setValue('levelId', data.levelId);
      setCheckedSubjects(data.subjects || []);
      setPercentages(data.percentage || {});
    }
  }, [type, data, setValue]);

  const onSubmit = (formData) => {
    const formDataWithSubjectsAndPercentage = {
      ...formData,
      subjects: checkedSubjects,
      percentage: percentages
    };

    console.log("Submitted Data:", formDataWithSubjectsAndPercentage);

    if (type === "create") {
      router.post("/offers", formDataWithSubjectsAndPercentage, {
        onSuccess: () => {
          setShowConfetti(true);
          setTimeout(() => setOpen(false), 2000);
        },
      });
    }
  };

  const handleCheckboxChange = (subject) => {
    if (checkedSubjects.includes(subject)) {
      setCheckedSubjects(checkedSubjects.filter(item => item !== subject));
      setPercentages(prev => {
        const updatedPercentages = { ...prev };
        delete updatedPercentages[subject];
        return updatedPercentages;
      });
    } else {
      setCheckedSubjects([...checkedSubjects, subject]);
    }
  };

  const handlePercentageChange = (subject, value) => {
    setPercentages(prev => ({
      ...prev,
      [subject]: value ? parseInt(value, 10) : ''
    }));
  };

  return (
    <div className="relative">
      {/* Confetti Animation */}
      {showConfetti && <Confetti recycle={false} numberOfPieces={500} />}

      <form
        className="grid grid-cols-1 md:grid-cols-2 gap-6 p-8 bg-white rounded-lg shadow-2xl border border-gray-200"
        onSubmit={handleSubmit(onSubmit)}
      >
        <h1 className="text-2xl font-bold text-gray-800 col-span-full">
          {type === "create" ? "Create New Offer" : "Update Offer"}
        </h1>

        {/* Offer Name */}
        <div className="relative col-span-full">
          <div className="flex items-center gap-3">
            <Tag className="w-5 h-5 text-gray-500" />
            <input
              type="text"
              {...register('offer_name', { required: 'Offer name is required' })}
              className="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Offer Name"
            />
          </div>
          {errors.offer_name && (
            <p className="text-sm text-red-500 mt-1">{errors.offer_name.message}</p>
          )}
        </div>

        {/* Price */}
        <div className="relative">
          <div className="flex items-center gap-3">
            <DollarSign className="w-5 h-5 text-gray-500" />
            <input
              type="number"
              {...register('price', { required: 'Price is required', min: 0 })}
              className="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Price"
            />
          </div>
          {errors.price && (
            <p className="text-sm text-red-500 mt-1">{errors.price.message}</p>
          )}
        </div>

        {/* Level Selection */}
        <div className="relative">
          <div className="flex items-center gap-3">
            <Layers className="w-5 h-5 text-gray-500" />
            <select
              {...register('levelId', { required: 'Level is required' })}
              className="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none"
            >
              <option value="">Select Level</option>
              {levels?.map((level) => (
                <option key={level.id} value={level.id}>
                  {level.name}
                </option>
              ))}
            </select>
          </div>
          {errors.levelId && (
            <p className="text-sm text-red-500 mt-1">{errors.levelId.message}</p>
          )}
        </div>

        {/* Subjects and Percentages */}
        <div className="col-span-full">
          <label className="text-sm font-medium text-gray-700 flex items-center gap-2 mb-4">
            <Book className="w-5 h-5 text-gray-500" />
            Subjects & Percentages
          </label>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {predefinedSubjects.map((subject, index) => (
              <div
                key={index}
                className="flex items-center justify-between p-4 bg-gray-50 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200"
              >
                <div className="flex items-center gap-3">
                  <div
                    className={`w-5 h-5 rounded-md border-2 border-gray-300 flex items-center justify-center cursor-pointer transition-colors duration-200 ${
                      checkedSubjects.includes(subject) ? 'bg-blue-500 border-blue-500' : 'bg-white'
                    }`}
                    onClick={() => handleCheckboxChange(subject)}
                  >
                    {checkedSubjects.includes(subject) && (
                      <Check className="w-3 h-3 text-white" />
                    )}
                  </div>
                  <span className="text-sm font-medium text-gray-700">{subject}</span>
                </div>
                {checkedSubjects.includes(subject) && (
                  <input
                    type="number"
                    value={percentages[subject] || ''}
                    onChange={(e) => handlePercentageChange(subject, e.target.value)}
                    placeholder="%"
                    className="w-16 p-1 border border-gray-300 rounded-md text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Submit Button */}
        <button
          type="submit"
          className="col-span-full w-full bg-blue-500 text-white p-3 rounded-lg hover:bg-blue-600 transition-all duration-200 shadow-md"
        >
          {type === "create" ? "Create Offer" : "Update Offer"}
        </button>
      </form>
    </div>
  );
};

export default OfferForm;