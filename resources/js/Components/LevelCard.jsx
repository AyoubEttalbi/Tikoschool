import FormModal from "./FormModal"
import { useState } from "react"
import { role } from "@/lib/data"
function LevelCard({ level }) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white transition-all duration-200 hover:shadow-md hover:-translate-y-1 flex">
            <div className="w-full text-left flex justify-between items-center p-4">
                <div className="flex items-center gap-3">
                    <div className={`p-2 rounded-lg ${level.color}`}>
                        <span className="font-bold text-sm">{level.name[0]}</span> {/* Placeholder Icon (first letter) */}
                    </div>
                    <span className="font-medium">{level.name}</span>
                </div>
                <FormModal table="level" type="delete" data={level} id={level.id} route="othersettings/levels"/>
            </div>
        </div>
    )
}
// Mock Levels Data


function LevelsList({levelsData = []}) {
    const [levels] = useState(levelsData)

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 md:mt-4">
            <div className="flex items-center justify-between mb-8">
                <div className="flex flex-row md:flex-row items-center gap-4 w-full md:w-auto">
                    <h1 className="font-semibold text-2xl">My Levels List</h1>
                    {role === "admin" && (
                        <FormModal table="level" type="create" />
                    )}
                </div>
            </div>
            <div className="container mx-auto">
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    {levels.map((level) => (
                        <LevelCard key={level.id} level={level} />
                    ))}
                </div>
            </div>
        </div>
    )
}

export default LevelsList
