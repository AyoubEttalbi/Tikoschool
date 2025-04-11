import React, { useState } from 'react';
import { ArrowRightCircle, CalendarPlus, ChevronRight, Info } from 'lucide-react';
import { router } from '@inertiajs/react';

function SchoolYearTransition() {
  const [showModal, setShowModal] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [confirmStep, setConfirmStep] = useState(1);
  const [confirmText, setConfirmText] = useState('');
  const [errors, setErrors] = useState(null);
  
  const handleTransition = () => {
    if (confirmText !== 'CONFIRM') {
      setErrors('Please type CONFIRM to proceed with the transition.');
      return;
    }
    
    setIsProcessing(true);
    
    // Call to backend API to process the school year transition
    router.post(route('schoolyear.transition'), {}, {
      preserveScroll: true,
      onSuccess: (response) => {
        setIsProcessing(false);
        setShowModal(false);
        setConfirmStep(1);
        setConfirmText('');
      },
      onError: (errors) => {
        setIsProcessing(false);
        setErrors(errors);
      }
    });
  };
  
  const steps = [
    "Promote students to the next grade level (if marked as promoted), with class assignments cleared and graduating students marked accordingly",
    "Students marked as not promoted will keep their current level but their class assignments will be cleared",
    "Remove all class assignments from teacher profiles (soft deleted to maintain historical records)",
    "Archive all current student memberships by marking them as completed and soft-deleting them",
    "Reset class enrollment counts while preserving historical student data",
    "Create a comprehensive record of the completed school year with detailed statistics"
  ];

  return (
    <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 md:mt-4">
      <div className="flex items-center justify-between mb-8">
        <div className="flex flex-row md:flex-row items-center gap-4 w-full md:w-auto">
          <h1 className="font-semibold text-2xl">School Year Management</h1>
        </div>
      </div>
      
      <div className="bg-gradient-to-r from-lamaSky/10 to-lamaPurple/10 p-6 rounded-lg border border-gray-200">
        <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
          <div>
            <h2 className="text-xl font-medium text-gray-800">End Current School Year</h2>
            <p className="text-gray-600 mt-1">
              Transition to the next school year by promoting students, resetting teacher assignments, and archiving current memberships.
              All data will be preserved in the database for historical records.
            </p>
          </div>
          <button
            onClick={() => setShowModal(true)}
            className="px-4 py-2 bg-lamaSky text-white rounded-md hover:bg-lamaSky/90 transition-colors flex items-center gap-2 shadow-sm"
          >
            <CalendarPlus className="w-5 h-5" />
            Start New School Year
          </button>
        </div>
      </div>

      {/* Confirmation Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 overflow-hidden">
            <div className="bg-gradient-to-r from-lamaSky to-lamaPurple p-4">
              <h3 className="text-white font-medium text-lg">School Year Transition</h3>
            </div>
            
            <div className="p-6">
              {confirmStep === 1 ? (
                <>
                  <div className="flex items-start gap-3 mb-4">
                    <div className="text-amber-500 mt-1">
                      <Info className="w-5 h-5" />
                    </div>
                    <div>
                      <h4 className="font-medium text-gray-800 mb-1">Important: This action cannot be undone</h4>
                      <p className="text-gray-600 text-sm">
                        Starting a new school year will make the following changes:
                      </p>
                      <ul className="mt-3 space-y-2">
                        {steps.map((step, index) => (
                          <li key={index} className="flex items-center gap-2 text-sm text-gray-700">
                            <ChevronRight className="w-4 h-4 text-lamaSky" />
                            {step}
                          </li>
                        ))}
                      </ul>
                    </div>
                  </div>
                  <div className="bg-blue-50 border border-blue-200 rounded-md p-3 mt-4">
                    <p className="text-sm text-blue-800">
                      <strong>Note:</strong> All historical data will be preserved in the database. This process will only
                      remove the associations from active profiles while maintaining the ability to access past records.
                    </p>
                  </div>
                </>
              ) : (
                <>
                  <div className="bg-red-50 border border-red-200 rounded-md p-4 mb-4">
                    <h4 className="font-medium text-red-800 mb-1">Final Confirmation Required</h4>
                    <p className="text-sm text-red-700">
                      You are about to start a new school year. This will reset teacher assignments, archive memberships, 
                      and promote students to the next grade level. While the data will be preserved in the database, 
                      the associations will be removed from active profiles.
                    </p>
                  </div>
                  <p className="text-sm text-gray-600 mb-2">
                    Type <strong>CONFIRM</strong> below to proceed:
                  </p>
                  <input
                    type="text"
                    value={confirmText}
                    onChange={(e) => setConfirmText(e.target.value)}
                    className="w-full border border-gray-300 rounded-md p-2 text-sm"
                    placeholder="Type CONFIRM here"
                  />
                </>
              )}
              
              {errors && (
                <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                  {typeof errors === 'string' ? errors : 'An error occurred during the transition process.'}
                </div>
              )}
              
              <div className="flex justify-end gap-3 mt-6">
                <button
                  onClick={() => {
                    setShowModal(false);
                    setConfirmStep(1);
                    setConfirmText('');
                    setErrors(null);
                  }}
                  className="px-3 py-1.5 border border-gray-300 rounded text-gray-700 text-sm hover:bg-gray-50"
                  disabled={isProcessing}
                >
                  Cancel
                </button>
                {confirmStep === 1 ? (
                  <button
                    onClick={() => setConfirmStep(2)}
                    className="px-3 py-1.5 bg-lamaSky text-white rounded text-sm hover:bg-lamaSky/90 flex items-center gap-1"
                  >
                    Next Step
                    <ArrowRightCircle className="w-4 h-4" />
                  </button>
                ) : (
                  <button
                    onClick={handleTransition}
                    disabled={isProcessing || confirmText !== 'CONFIRM'}
                    className="px-3 py-1.5 bg-red-600 text-white rounded text-sm hover:bg-red-700 flex items-center gap-1 disabled:opacity-70"
                  >
                    {isProcessing ? "Processing..." : "Confirm Transition"}
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default SchoolYearTransition; 