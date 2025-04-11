import React from 'react';
import { GraduationCap, AlertCircle, ArrowUpRight, Check, X, HelpCircle } from 'lucide-react';

export default function PromotionGuide({ onSetupClick, showPromotionTools = false }) {
  return (
    <div className="mb-4">
      {!showPromotionTools ? (
        <div className="bg-blue-50 border border-blue-200 rounded-md p-3 flex items-start gap-3">
          <div className="text-blue-600 mt-0.5">
            <AlertCircle size={18} />
          </div>
          <div>
            <h3 className="text-blue-800 font-medium text-sm">Prepare for Year-End Promotions</h3>
            <p className="text-blue-700 text-sm mt-1">
              You can manage which students should be promoted to the next grade level at the end of the school year.
            </p>
            <button 
              onClick={onSetupClick}
              className="mt-2 px-3 py-1.5 bg-blue-600 text-white rounded-md text-sm flex items-center gap-1"
            >
              <GraduationCap size={16} />
              Set Up Student Promotions
            </button>
            
            <details className="mt-3 text-sm text-blue-700">
              <summary className="cursor-pointer flex items-center gap-1">
                <HelpCircle size={14} />
                <span>How does the promotion process work?</span>
              </summary>
              <div className="mt-2 pl-2 border-l-2 border-blue-200">
                <ol className="list-decimal pl-5 space-y-1">
                  <li>Click the button above to set up promotions for all students in this class</li>
                  <li>Set the promotion status for each student (promoted or not promoted)</li>
                  <li>Add optional notes for any special circumstances</li>
                  <li>After saving, you can still change individual student promotion statuses</li>
                  <li>At the end of the school year, these settings will determine which students advance to the next grade level</li>
                </ol>
              </div>
            </details>
          </div>
        </div>
      ) : (
        <div className="bg-green-50 border border-green-200 rounded-md p-3">
          <h3 className="text-green-800 font-medium text-sm flex items-center gap-1">
            <GraduationCap size={16} />
            Student Promotion Management
          </h3>
          <p className="text-green-700 text-sm mt-1">
            Click on any student's "Promotion Status" section to change their promotion status.
          </p>
          
          <div className="mt-3 flex flex-col gap-2 text-sm">
            <div className="flex items-start gap-2">
              <div className="flex h-5 items-center">
                <Check size={16} className="text-green-600" />
              </div>
              <p className="text-green-700">
                <span className="font-medium">Promoted students</span> will advance to the next grade level at the end of the year
              </p>
            </div>
            <div className="flex items-start gap-2">
              <div className="flex h-5 items-center">
                <X size={16} className="text-red-600" />
              </div>
              <p className="text-red-700">
                <span className="font-medium">Non-promoted students</span> will remain at their current grade level
              </p>
            </div>
            <div className="flex items-start gap-2">
              <div className="flex h-5 items-center">
                <ArrowUpRight size={16} className="text-blue-600" />
              </div>
              <p className="text-blue-700">
                <span className="font-medium">Notes</span> can be added to explain promotion decisions
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
} 