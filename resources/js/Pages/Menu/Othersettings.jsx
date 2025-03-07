import LevelsList from '@/Components/LevelCard';
import SubjectsList from '@/Components/SubjectCard';
import DashboardLayout from '@/Layouts/DashboardLayout';
import React from 'react'

export default function Othersettings({levels , subjects}) {
  console.log("levelssssss" ,levels);
  return (
    <div>
      <SubjectsList subjectsData={subjects}/>
      <LevelsList levelsData={levels}/>
    </div>
  )
}
Othersettings.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;