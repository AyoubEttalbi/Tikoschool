import { useState } from "react"
import { Code, Palette, BookOpen, Calculator, Globe, Microscope, Music, Dumbbell } from "lucide-react"
import FormModal from "./FormModal"
import { role } from "@/lib/data"

// Mock database of subjects
const subjectsData = [
    { id: "1", name: "Programming", icon: Code, color: "bg-blue-100 text-blue-700" },
    { id: "2", name: "Design", icon: Palette, color: "bg-purple-100 text-purple-700" },
    { id: "3", name: "Literature", icon: BookOpen, color: "bg-yellow-100 text-yellow-700" },
    { id: "4", name: "Mathematics", icon: Calculator, color: "bg-green-100 text-green-700" },
    { id: "5", name: "Languages", icon: Globe, color: "bg-red-100 text-red-700" },
    { id: "6", name: "Science", icon: Microscope, color: "bg-cyan-100 text-cyan-700" },
    { id: "7", name: "Music", icon: Music, color: "bg-pink-100 text-pink-700" },
    { id: "8", name: "Physical Education", icon: Dumbbell, color: "bg-orange-100 text-orange-700" },
]

function SubjectCard({ subject }) {
    const Icon = subject.icon

    return (
      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white transition-all duration-200 hover:shadow-md hover:-translate-y-1 flex flex-">
      <div className="w-full text-left flex justify-between items-center p-4">
          <div className="flex items-center gap-3">
              <div className={`p-2 rounded-lg ${subject.color}`}>
                  <Icon className="h-5 w-5" />
              </div>
              <span className="font-medium">{subject.name}</span>
          </div>
          <FormModal table="subject" type="delete" data={subject} />
      </div>
  </div>
    )
}

function SubjectsList() {
    const [subjects] = useState(subjectsData)

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 md:mt-4">
        <div className="flex items-center justify-between mb-8">
          
          <div className="flex flex-row md:flex-row items-center gap-4 w-full md:w-auto">
          < h1 className=" font-semibold text-2xl">My Subjects List</ h1>
           
              
              {role === "admin" && (
                <FormModal table="subject" type="create" />
              )}
           
          </div>
        </div>
        <div className="container mx-auto">
         
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            {subjects.map((subject) => (
              <SubjectCard key={subject.id} subject={subject} />
            ))}
          </div>
        </div>
      </div>


    )
}
export default SubjectsList
// function App() {
//   return (
//     <div className="min-h-screen p-4">
//       <SubjectsList />
//     </div>
//   )
// }

// export default App