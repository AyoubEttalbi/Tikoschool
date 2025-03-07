import { useState, useEffect } from "react";
import { Code, Palette, BookOpen, Calculator, Globe, Microscope, Music, Dumbbell } from "lucide-react";
import FormModal from "./FormModal";
import { role } from "@/lib/data";

// Icon mapping
const iconComponents = {
  Code,
  Palette,
  BookOpen,
  Calculator,
  Globe,
  Microscope,
  Music,
  Dumbbell,
};

function SubjectCard({ subject }) {
    const Icon = iconComponents[subject.icon]; // Get the component from the mapping

    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white transition-all duration-200 hover:shadow-md hover:-translate-y-1 flex">
            <div className="w-full text-left flex justify-between items-center p-4">
                <div className="flex items-center gap-3">
                    <div className={`p-2 rounded-lg ${subject.color}`}>
                        {Icon && <Icon className="h-5 w-5" />} {/* Render the icon if it exists */}
                    </div>
                    <span className="font-medium">{subject.name}</span>
                </div>
                <FormModal table="subject" type="delete" data={subject} id={subject.id} route="othersettings/subjects" />
            </div>
        </div>
    );
}

function SubjectsList({ subjectsData = [] }) {
  return (
      <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 md:mt-4">
          <div className="flex items-center justify-between mb-8">
              <div className="flex flex-row md:flex-row items-center gap-4 w-full md:w-auto">
                  <h1 className="font-semibold text-2xl">My Subjects List</h1>
                  {role === "admin" && (
                      <FormModal table="subject" type="create" />
                  )}
              </div>
          </div>
          <div className="container mx-auto">
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                  {subjectsData.map((subject) => (
                      <SubjectCard key={subject.id} subject={subject} />
                  ))}
              </div>
          </div>
      </div>
  );
}
export default SubjectsList