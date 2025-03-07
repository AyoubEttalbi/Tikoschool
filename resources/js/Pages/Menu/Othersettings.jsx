import LevelsList from '@/Components/LevelCard';
import SubjectsList from '@/Components/SubjectCard';
import DashboardLayout from '@/Layouts/DashboardLayout';
import React from 'react'

export default function Othersettings({levels}) {
  console.log("levelssssss" ,levels);
  return (
    <div>
      <SubjectsList />
      <LevelsList levelsData={levels}/>
    </div>
  )
}
Othersettings.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;