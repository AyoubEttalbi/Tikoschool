
import { Clock, Edit, GraduationCap, Plus, Users } from "lucide-react"

export default function MembershipCard() {
  return (
    <div className="w-full max-w-lg p-6 bg-white rounded-lg shadow-sm">
      <div className="flex justify-between items-center mb-6">
        <div className="flex items-center gap-2 text-blue-600 font-medium">
          <Users className="h-5 w-5" />
          <span>Memberships:</span>
        </div>
        <button className="bg-blue-500 hover:bg-blue-600 text-white rounded-full px-3 py-1 h-8 flex items-center">
          <Plus className="h-4 w-4 mr-1" />
          New
        </button>
      </div>

      <div className="border border-gray-200 rounded-md">
        <div className="p-4">
          <div className="flex justify-between items-start">
            <div className="space-y-4">
              <div className="flex items-center gap-2 text-gray-700 font-medium">
                <GraduationCap className="h-5 w-5 text-gray-600" />
                <span>
                  Offer: <span className="text-gray-800">AC MATH SVT</span>
                </span>
              </div>

              <div className="space-y-1">
                <div className="flex items-center gap-2 text-gray-700 font-medium">
                  <Users className="h-5 w-5 text-gray-600" />
                  <span>Teachers:</span>
                </div>

                <div className="ml-7 space-y-2">
                  <div className="flex items-center gap-2 text-gray-600">
                    <Users className="h-4 w-4" />
                    <span>
                      Math : <span className="text-blue-600">hamouda chakiri</span>
                    </span>
                  </div>
                  <div className="flex items-center gap-2 text-gray-600">
                    <Users className="h-4 w-4" />
                    <span>
                      SVT : <span className="text-blue-600">ayoub el mahdaoui</span>
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <button className="text-blue-500 hover:text-blue-600">
              <Edit className="h-5 w-5" />
            </button>
          </div>

          <div className="flex items-center gap-1 text-gray-500 text-sm mt-4 justify-end">
            <Clock className="h-4 w-4" />
            <span>16-Aug-2024 | 21:30</span>
          </div>
        </div>
      </div>
    </div>
  )
}